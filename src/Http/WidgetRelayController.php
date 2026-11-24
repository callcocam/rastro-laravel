<?php

namespace Callcocam\Rastro\Http;

use Callcocam\Rastro\Central;
use Callcocam\Rastro\Context\ServerTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * The widget's endpoints on the client site. The
 * browser talks only to this site — same origin, its session and login —
 * and this relays to the support central with the shared key, adding who is
 * logged in and the server trail of the visitor's session. Nothing is
 * stored here.
 */
class WidgetRelayController extends Controller
{
    public function __construct(protected Central $central, protected ServerTrail $trail) {}

    /**
     * The central's texts and help tree (cached a minute) plus, for a
     * logged-in user, the name and e-mail to prefill.
     */
    public function config(Request $request): JsonResponse
    {
        $central = Cache::remember('rastro:central-config:'.app()->getLocale(), 60, function () {
            $response = $this->central->send('GET', 'widget/config', headers: $this->localeHeader());

            return $response?->successful() ? $response->json() : null;
        });

        if (! is_array($central)) {
            Cache::forget('rastro:central-config:'.app()->getLocale());

            return $this->unavailable();
        }

        $user = $request->user();

        return response()->json([
            ...$central,
            'user' => $user ? [
                'name' => (string) ($user->name ?? ''),
                'email' => (string) ($user->email ?? ''),
            ] : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->relay($request, 'POST', 'widget/conversations', withContext: true);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        return $this->relay($request, 'GET', "widget/conversations/{$conversation}");
    }

    public function message(Request $request, string $conversation): JsonResponse
    {
        return $this->relay($request, 'POST', "widget/conversations/{$conversation}/messages", withContext: true);
    }

    public function handoff(Request $request, string $conversation): JsonResponse
    {
        return $this->relay($request, 'POST', "widget/conversations/{$conversation}/handoff");
    }

    public function close(Request $request, string $conversation): JsonResponse
    {
        return $this->relay($request, 'POST', "widget/conversations/{$conversation}/close");
    }

    public function rate(Request $request, string $conversation): JsonResponse
    {
        return $this->relay($request, 'POST', "widget/conversations/{$conversation}/rating");
    }

    public function feedback(Request $request, string $node): JsonResponse
    {
        return $this->relay($request, 'POST', "widget/help/{$node}/feedback");
    }

    protected function relay(Request $request, string $method, string $path, bool $withContext = false): JsonResponse
    {
        $body = $request->only(['name', 'email', 'message', 'body', 'help_path', 'context', 'helpful', 'rating', 'comment']);

        if ($withContext) {
            $body['visitor'] = [
                'user_id' => ($id = $request->user()?->getAuthIdentifier()) !== null ? (string) $id : null,
                'user_agent' => (string) $request->userAgent(),
            ];
            $body['server_events'] = config('rastro.context.enabled', true) && $request->hasSession()
                ? $this->trail->recent($request->session()->getId())
                : [];
        }

        $response = $this->central->send($method, $path, $body, array_filter([
            'X-Support-Token' => (string) $request->header('X-Support-Token'),
            ...$this->localeHeader(),
        ]));

        if ($response === null || $response->serverError()) {
            return $this->unavailable();
        }

        // 401 means the key is wrong: the visitor sees "unavailable", the log says why.
        if ($response->status() === 401) {
            logger()->warning('rastro: the central refused the key (RASTRO_KEY)');

            return $this->unavailable();
        }

        return response()->json($response->json() ?? [], $response->status());
    }

    /**
     * @return array<string, string>
     */
    protected function localeHeader(): array
    {
        return ['Accept-Language' => str_replace('_', '-', app()->getLocale())];
    }

    protected function unavailable(): JsonResponse
    {
        return response()->json(['message' => __('rastro::rastro.unavailable')], 503);
    }
}
