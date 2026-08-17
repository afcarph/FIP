<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Support\Exceptions\AiServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Typed HTTP client for the Python AI microservice.
 *
 * Every call is bounded by a timeout and a bounded retry so a slow model can
 * never hold an API worker hostage; failures surface as
 * {@see AiServiceException} which the renderer maps to 502/503.
 */
class AiServiceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
    ) {}

    /** Weekly pump price forecast for one fuel type. */
    public function forecastPrice(array $payload): array
    {
        return $this->post('/api/v1/predict/price', $payload);
    }

    /** Per-vehicle consumption projection. */
    public function predictConsumption(array $payload): array
    {
        return $this->post('/api/v1/predict/consumption', $payload);
    }

    /** Next-service estimate for a vehicle's service items. */
    public function predictMaintenance(array $payload): array
    {
        return $this->post('/api/v1/predict/maintenance', $payload);
    }

    /** Anomaly score for a batch of fuel transactions. */
    public function detectFraud(array $payload): array
    {
        return $this->post('/api/v1/detect/fraud', $payload);
    }

    /** Regional demand forecast used for station stock planning. */
    public function forecastDemand(array $payload): array
    {
        return $this->post('/api/v1/predict/demand', $payload);
    }

    /** Cost-optimised route alternatives with refuelling stops. */
    public function optimizeRoute(array $payload): array
    {
        return $this->post('/api/v1/optimize/route', $payload);
    }

    /** Conversational fuel advisor turn. */
    public function chat(array $payload): array
    {
        return $this->post('/api/v1/assistant/chat', $payload, timeout: 60);
    }

    /**
     * Run OCR over a price-board photograph.
     *
     * @param string $contents raw image bytes
     */
    public function scanPriceBoard(string $contents, string $filename): array
    {
        try {
            $response = $this->request(timeout: 45)
                ->attach('file', $contents, $filename)
                ->post($this->url('/api/v1/ocr/price-board'));
        } catch (ConnectionException $e) {
            throw AiServiceException::unavailable('/api/v1/ocr/price-board', $e->getMessage());
        }

        return $this->unwrap($response, '/api/v1/ocr/price-board');
    }

    /**
     * Run OCR over a fill-up receipt.
     *
     * Returns a draft with per-field confidence; it records nothing. Creating
     * the purchase stays with FuelExpenseService, which knows the vehicle and
     * the odometer the receipt must not contradict.
     *
     * @param string $contents raw image bytes
     */
    public function scanReceipt(string $contents, string $filename): array
    {
        try {
            $response = $this->request(timeout: 45)
                ->attach('file', $contents, $filename)
                ->post($this->url('/api/v1/ocr/receipt'));
        } catch (ConnectionException $e) {
            throw AiServiceException::unavailable('/api/v1/ocr/receipt', $e->getMessage());
        }

        return $this->unwrap($response, '/api/v1/ocr/receipt');
    }

    public function health(): bool
    {
        try {
            return $this->request(timeout: 5)->get($this->url('/health'))->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    // ------------------------------------------------------------ internals

    protected function post(string $endpoint, array $payload, ?int $timeout = null): array
    {
        try {
            $response = $this->request($timeout)->post($this->url($endpoint), $payload);
        } catch (ConnectionException $e) {
            Log::warning('AI service unreachable', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            throw AiServiceException::unavailable($endpoint, $e->getMessage());
        }

        return $this->unwrap($response, $endpoint);
    }

    protected function request(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($timeout ?? $this->timeout)
            ->connectTimeout(5)
            ->retry($this->retries, 250, throw: false)
            ->withHeaders(array_filter([
                'Accept' => 'application/json',
                'X-Service-Token' => $this->token,
                'X-Request-Id' => request()?->attributes->get('request_id'),
            ]));
    }

    protected function unwrap(Response $response, string $endpoint): array
    {
        if ($response->failed()) {
            Log::warning('AI service returned an error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw AiServiceException::badResponse($endpoint, $response->status());
        }

        return (array) $response->json();
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/').$endpoint;
    }
}
