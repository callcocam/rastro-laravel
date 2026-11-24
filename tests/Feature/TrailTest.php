<?php

use Callcocam\Rastro\Context\RecordServerTrail;
use Callcocam\Rastro\Context\Sanitizer;
use Callcocam\Rastro\Context\ServerTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * A request carrying a started session, as the `web` group would give it.
 */
function requestWithSession(string $uri = '/cadastro', string $method = 'POST'): Request
{
    $request = Request::create($uri, $method);
    $session = new Store('test', app('session')->driver()->getHandler());
    $session->start();
    $request->setLaravelSession($session);
    app()->instance('request', $request);

    return $request;
}

it('hides secrets, masks long numbers and drops query values', function () {
    $sanitizer = Sanitizer::fromConfig();

    expect($sanitizer->clean(['password' => 'x', 'user' => ['api_key' => 'k', 'name' => 'Ana']]))
        ->toBe(['password' => '[oculto]', 'user' => ['api_key' => '[oculto]', 'name' => 'Ana']])
        ->and($sanitizer->text('CPF 123.456.789-09 e cartão 4111 1111 1111 1111'))
        ->toBe('CPF •••••••••09 e cartão ••••••••••••••11')
        ->and($sanitizer->text('Authorization: Bearer abc.def.ghi'))->toBe('Authorization: Bearer [oculto]')
        ->and($sanitizer->url('https://app.test/cadastro?step=2&email=ana@x.test'))->toBe('/cadastro?step&email');
});

it('notes the validation errors a form was sent back with, never the values', function () {
    $request = requestWithSession();
    $request->session()->put('errors', (new ViewErrorBag)->put('default', new MessageBag([
        'email' => ['O e-mail já está em uso.'],
        'document' => ['O CPF 123.456.789-09 é inválido.'],
    ])));

    app(RecordServerTrail::class)->handle($request, fn () => new RedirectResponse('/cadastro'));

    $events = app(ServerTrail::class)->recent($request->session()->getId());

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('validation')
        ->and($events[0]['url'])->toBe('/cadastro')
        ->and($events[0]['errors'])->toBe([
            'email' => 'O e-mail já está em uso.',
            'document' => 'O CPF •••••••••09 é inválido.',
        ]);
});

it('notes json 422s and failed requests, and leaves successful ones alone', function () {
    $request = requestWithSession('/api/pedido');
    $middleware = app(RecordServerTrail::class);

    $middleware->handle($request, fn () => new JsonResponse(['errors' => ['qty' => ['Mínimo 1.']]], 422));
    $middleware->handle($request, fn () => new JsonResponse(['ok' => false], 500));
    $middleware->handle($request, fn () => new JsonResponse(['ok' => true], 200));

    $events = app(ServerTrail::class)->recent($request->session()->getId());

    expect(array_column($events, 'type'))->toBe(['validation', 'http_error'])
        ->and($events[0]['errors'])->toBe(['qty' => 'Mínimo 1.'])
        ->and($events[1]['status'])->toBe(500);
});

it('notes log errors of the session, with where in the project they came from', function () {
    $request = requestWithSession('/checkout');

    Log::warning('just a warning');
    Log::error('Falha ao gerar boleto', ['exception' => new RuntimeException('Gateway fora do ar'), 'token' => 'segredo']);

    $events = app(ServerTrail::class)->recent($request->session()->getId());

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('log')
        ->and($events[0]['message'])->toBe('Falha ao gerar boleto')
        ->and($events[0]['exception'])->toBe(RuntimeException::class)
        ->and($events[0]['url'])->toBe('/checkout')
        ->and(json_encode($events))->not->toContain('segredo');
});
