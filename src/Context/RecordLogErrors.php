<?php

namespace Callcocam\Rastro\Context;

use Illuminate\Log\Events\MessageLogged;
use Throwable;

/**
 * Notes the app's own log errors in the trail of the session that caused
 * them: message, exception class and the file:line inside the project. The
 * log context itself is not copied — it can hold anything.
 */
class RecordLogErrors
{
    /** Monolog order. */
    protected const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    protected static bool $recording = false;

    public function __construct(protected ServerTrail $trail, protected Sanitizer $sanitizer) {}

    public function handle(MessageLogged $event): void
    {
        // The trail itself might log (cache down…): never loop.
        if (self::$recording || ! $this->reaches($event->level)) {
            return;
        }

        self::$recording = true;

        try {
            $request = app()->bound('request') ? app('request') : null;

            if ($request === null || ! $request->hasSession()) {
                return;
            }

            $exception = $event->context['exception'] ?? null;

            $this->trail->push($request->session()->getId(), array_filter([
                'type' => 'log',
                'level' => $event->level,
                'message' => $this->sanitizer->text($event->message, 300),
                'exception' => $exception instanceof Throwable ? $exception::class : null,
                'where' => $exception instanceof Throwable ? $this->where($exception) : null,
                'url' => $this->sanitizer->url($request->getRequestUri()),
            ]));
        } catch (Throwable) {
            // Recording must not break logging.
        } finally {
            self::$recording = false;
        }
    }

    protected function reaches(string $level): bool
    {
        $minimum = config('rastro.context.log_level');

        if (! $minimum) {
            return false;
        }

        $at = array_search(strtolower($level), self::LEVELS, true);
        $from = array_search(strtolower((string) $minimum), self::LEVELS, true);

        return $at !== false && $from !== false && $at >= $from;
    }

    /**
     * The first frame inside the project (not vendor), relative to its root.
     */
    protected function where(Throwable $exception): string
    {
        $root = base_path().DIRECTORY_SEPARATOR;
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()];

        foreach ($frames as $frame) {
            $file = (string) ($frame['file'] ?? '');

            if ($file !== '' && str_starts_with($file, $root) && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                return substr($file, strlen($root)).':'.($frame['line'] ?? '?');
            }
        }

        return basename($exception->getFile()).':'.$exception->getLine();
    }
}
