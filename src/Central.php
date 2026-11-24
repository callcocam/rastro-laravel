<?php

namespace Callcocam\Rastro;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The support central, seen from this site:
 * its URL and the shared key, server to server.
 */
class Central
{
    public function __construct(
        protected string $url,
        protected string $key,
        protected int $timeout = 10,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            url: rtrim((string) config('rastro.url'), '/'),
            key: (string) config('rastro.key'),
            timeout: (int) config('rastro.timeout', 10),
        );
    }

    public function configured(): bool
    {
        return $this->url !== '' && $this->key !== '';
    }

    /**
     * One call to the central's API; null when it cannot be reached.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $path, array $body = [], array $headers = [], ?int $timeout = null): ?Response
    {
        if (! $this->configured()) {
            Log::warning('rastro: set RASTRO_URL and RASTRO_KEY');

            return null;
        }

        try {
            return $this->request($headers, $timeout)->send($method, $this->url.'/'.ltrim($path, '/'), $method === 'GET' ? [] : ['json' => $body]);
        } catch (Throwable $exception) {
            Log::warning('rastro: central unreachable', ['path' => $path, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function request(array $headers, ?int $timeout): PendingRequest
    {
        return Http::withToken($this->key)
            ->acceptJson()
            ->timeout($timeout ?? $this->timeout)
            ->withHeaders($headers);
    }
}
