<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\User\Models\User;
use App\Services\External\FcmClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out for in-app and push notifications.
 *
 * Delivery respects three user controls, checked in order: the per-category
 * opt-out, quiet hours, and whether the device still has a live FCM token.
 * An in-app record is always written even when push is suppressed, so the
 * notification centre stays complete.
 */
final readonly class NotificationService
{
    /** Category → preference column on user_preferences. */
    private const PREFERENCE_MAP = [
        'price' => 'notify_price_alerts',
        'forecast' => 'notify_ai_insights',
        'maintenance' => 'notify_maintenance',
        'document' => 'notify_maintenance',
        'ai' => 'notify_ai_insights',
        'marketing' => 'notify_marketing',
    ];

    public function __construct(private FcmClient $fcm) {}

    /**
     * Send one notification to one user.
     *
     * @param  array{title?: string, body?: string, template?: string, variables?: array,
     *               data?: array, action_url?: string, priority?: string}  $payload
     */
    public function send(User $user, string $type, string $category, array $payload): ?Notification
    {
        $content = $this->resolveContent($payload);

        if ($content === null) {
            Log::warning('Notification skipped: no content', ['type' => $type, 'user_id' => $user->getKey()]);

            return null;
        }

        $notification = Notification::create([
            'user_id' => $user->getKey(),
            'type' => $type,
            'category' => $category,
            'title' => $content['title'],
            'body' => $content['body'],
            'data' => $payload['data'] ?? null,
            'action_url' => $payload['action_url'] ?? null,
            'priority' => $payload['priority'] ?? 'normal',
        ]);

        if ($this->shouldPush($user, $category, $payload['priority'] ?? 'normal')) {
            $this->push($user, $notification);
        }

        return $notification;
    }

    /**
     * Send the same notification to many users, batching push delivery.
     *
     * @param Collection<int, User> $users
     */
    public function sendMany(Collection $users, string $type, string $category, array $payload): int
    {
        $sent = 0;

        foreach ($users as $user) {
            if ($this->send($user, $type, $category, $payload) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    public function markAllRead(User $user): int
    {
        return $user->appNotifications()->unread()->update(['read_at' => now()]);
    }

    public function unreadCount(User $user): int
    {
        return $user->appNotifications()->unread()->count();
    }

    // ------------------------------------------------------------ internals

    private function push(User $user, Notification $notification): void
    {
        $tokens = $user->devices()->pushable()->pluck('fcm_token')->filter()->all();

        if ($tokens === []) {
            return;
        }

        try {
            $invalid = $this->fcm->sendToTokens($tokens, [
                'title' => $notification->title,
                'body' => $notification->body,
                'data' => array_merge($notification->data ?? [], [
                    'notification_id' => $notification->getKey(),
                    'category' => $notification->category,
                    'action_url' => (string) $notification->action_url,
                ]),
                'priority' => $notification->priority,
            ]);

            // FCM tells us which tokens are dead; clear them so we stop trying.
            if ($invalid !== []) {
                $user->devices()->whereIn('fcm_token', $invalid)->update(['fcm_token' => null]);
            }

            $notification->forceFill(['sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('Push delivery failed', [
                'user_id' => $user->getKey(),
                'notification_id' => $notification->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldPush(User $user, string $category, string $priority): bool
    {
        $preferences = $user->preferences;

        if ($preferences === null) {
            return true;   // no preferences row yet — default to sending
        }

        $column = self::PREFERENCE_MAP[$category] ?? null;

        if ($column !== null && $preferences->{$column} === false) {
            return false;
        }

        // Urgent notifications (fraud, overdue registration) ignore quiet hours.
        return $priority === 'urgent' || ! $preferences->isWithinQuietHours();
    }

    /** @return array{title: string, body: string}|null */
    private function resolveContent(array $payload): ?array
    {
        if (! empty($payload['template'])) {
            $rendered = NotificationTemplate::render(
                $payload['template'],
                $payload['variables'] ?? [],
                $payload['channel'] ?? 'push',
            );

            if ($rendered !== null) {
                return $rendered;
            }
        }

        if (! empty($payload['title']) && ! empty($payload['body'])) {
            return ['title' => $payload['title'], 'body' => $payload['body']];
        }

        return null;
    }
}
