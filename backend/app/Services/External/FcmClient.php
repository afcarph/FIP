<?php

declare(strict_types=1);

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (HTTP v1) sender.
 *
 * FCM v1 has no multicast endpoint, so tokens are sent individually and the
 * dead ones (UNREGISTERED / INVALID_ARGUMENT) are returned to the caller for
 * cleanup rather than being retried forever.
 */
class FcmClient
{
    public function __construct(
        private readonly ?string $serverKey,
        private readonly ?string $projectId,
        private readonly string $endpointTemplate = 'https://fcm.googleapis.com/v1/projects/%s/messages:send',
    ) {}

    /**
     * @param  list<string>  $tokens
     * @param  array{title: string, body: string, data?: array, priority?: string}  $message
     * @return list<string> tokens FCM reported as permanently invalid
     */
    public function sendToTokens(array $tokens, array $message): array
    {
        if ($this->serverKey === null || $this->projectId === null) {
            Log::info('FCM not configured; push suppressed', ['tokens' => count($tokens)]);

            return [];
        }

        $endpoint = sprintf($this->endpointTemplate, $this->projectId);
        $invalid = [];

        foreach ($tokens as $token) {
            $response = Http::withToken($this->serverKey)
                ->timeout(10)
                ->retry(2, 200, throw: false)
                ->post($endpoint, ['message' => $this->buildMessage($token, $message)]);

            if ($response->successful()) {
                continue;
            }

            $status = $response->json('error.status');

            if (in_array($status, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true)) {
                $invalid[] = $token;

                continue;
            }

            Log::warning('FCM delivery error', [
                'status' => $response->status(),
                'error' => $status,
                'body' => mb_substr($response->body(), 0, 300),
            ]);
        }

        return $invalid;
    }

    private function buildMessage(string $token, array $message): array
    {
        // FCM data payloads must be flat string→string maps.
        $data = array_map(
            static fn ($value) => is_scalar($value) ? (string) $value : json_encode($value),
            $message['data'] ?? [],
        );

        $highPriority = in_array($message['priority'] ?? 'normal', ['high', 'urgent'], true);

        return [
            'token' => $token,
            'notification' => [
                'title' => $message['title'],
                'body' => $message['body'],
            ],
            'data' => $data,
            'android' => [
                'priority' => $highPriority ? 'HIGH' : 'NORMAL',
                'notification' => ['channel_id' => 'fip_default', 'sound' => 'default'],
            ],
            'apns' => [
                'headers' => ['apns-priority' => $highPriority ? '10' : '5'],
                'payload' => ['aps' => ['sound' => 'default', 'content-available' => 1]],
            ],
        ];
    }
}
