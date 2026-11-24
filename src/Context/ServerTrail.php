<?php

namespace Callcocam\Rastro\Context;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What the server saw of one session in the last minutes: validation errors,
 * failed requests and log errors. Lives only in the cache, a short ring per
 * session id, and is copied into a conversation when the person asks for
 * help. Never throws: recording must not break the request it watches.
 */
class ServerTrail
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function push(string $sessionId, array $event): void
    {
        if ($sessionId === '') {
            return;
        }

        try {
            $key = $this->key($sessionId);
            $events = (array) $this->cache()->get($key, []);
            $events[] = ['at' => now()->toIso8601String(), ...$event];

            $this->cache()->put(
                $key,
                array_slice($events, -$this->maxEvents()),
                now()->addMinutes($this->minutes()),
            );
        } catch (Throwable) {
            // A cache outage only costs the context, never the request.
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(string $sessionId, ?string $after = null): array
    {
        if ($sessionId === '') {
            return [];
        }

        try {
            $events = (array) $this->cache()->get($this->key($sessionId), []);
        } catch (Throwable) {
            return [];
        }

        if ($after === null) {
            return array_values($events);
        }

        return array_values(array_filter($events, fn (array $event) => ($event['at'] ?? '') > $after));
    }

    protected function key(string $sessionId): string
    {
        return 'rastro:trail:'.hash('sha256', $sessionId);
    }

    protected function cache(): Repository
    {
        return Cache::store(config('rastro.context.cache_store'));
    }

    protected function minutes(): int
    {
        return max(1, (int) config('rastro.context.minutes', 30));
    }

    protected function maxEvents(): int
    {
        return max(5, (int) config('rastro.context.max_events', 40));
    }
}
