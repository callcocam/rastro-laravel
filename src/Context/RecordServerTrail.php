<?php

namespace Callcocam\Rastro\Context;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes, per session, the requests that went wrong for the person: a form
 * sent back with validation errors (redirect + errors in the session, the
 * Inertia/Blade way, or a JSON 422) and any 4xx/5xx answer. Field names and
 * messages only — never what was typed.
 */
class RecordServerTrail
{
    public function __construct(protected ServerTrail $trail, protected Sanitizer $sanitizer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->hasSession() || $this->isSupportRoute($request)) {
            return $response;
        }

        $event = $this->describe($request, $response);

        if ($event !== null) {
            $this->trail->push($request->session()->getId(), $event);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function describe(Request $request, Response $response): ?array
    {
        $base = [
            'method' => $request->method(),
            'url' => $this->sanitizer->url($request->getRequestUri()),
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
        ];

        $errors = $this->validationErrors($request, $response);

        if ($errors !== null) {
            return ['type' => 'validation', ...$base, 'errors' => $errors];
        }

        if ($response->getStatusCode() >= 400) {
            return ['type' => 'http_error', ...$base];
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    protected function validationErrors(Request $request, Response $response): ?array
    {
        $messages = null;

        if ($response instanceof RedirectResponse) {
            $bag = $request->session()->get('errors');

            if ($bag instanceof ViewErrorBag && $bag->any()) {
                $messages = $bag->getBag('default')->toArray();
            }
        } elseif ($response instanceof JsonResponse && $response->getStatusCode() === 422) {
            $messages = (array) ($response->getData(true)['errors'] ?? []);
        }

        if (! $messages) {
            return null;
        }

        $errors = [];

        foreach (array_slice($messages, 0, 20, true) as $field => $list) {
            $errors[(string) $field] = $this->sanitizer->text((string) (is_array($list) ? ($list[0] ?? '') : $list), 200);
        }

        return $errors;
    }

    protected function isSupportRoute(Request $request): bool
    {
        $prefixes = array_filter([
            config('rastro.widget.prefix'),
        ]);

        foreach ($prefixes as $prefix) {
            if ($request->is(trim((string) $prefix, '/'), trim((string) $prefix, '/').'/*')) {
                return true;
            }
        }

        return false;
    }
}
