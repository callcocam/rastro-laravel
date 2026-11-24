<?php

use Callcocam\Rastro\Context\ServerTrail;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
});

it('has only the chat endpoints', function () {
    expect(Route::has('rastro.widget.config'))->toBeTrue()
        ->and(Route::has('rastro.widget.store'))->toBeTrue()
        ->and(collect(Route::getRoutes()->getRoutes())->map->uri()->filter(fn ($uri) => str_contains($uri, 'inbox'))->all())->toBe([]);
});

it('serves the central texts with the logged-in user of this site', function () {
    Http::fake(['central.test/*' => Http::response(['strings' => ['title' => 'Suporte'], 'help' => [], 'max_length' => 2000, 'poll_ms' => 3000, 'context' => true])]);

    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Maria', 'email' => 'maria@site.test']))
        ->getJson('support/widget/config')
        ->assertOk()
        ->assertJsonPath('strings.title', 'Suporte')
        ->assertJsonPath('user.email', 'maria@site.test');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://central.test/support/api/widget/config'
        && $request->header('Authorization') === ['Bearer central-key']);
});

it('relays a new chat with who is logged in and the server trail of the session', function () {
    Http::fake(['central.test/*' => Http::response(['id' => '01jz0000000000000000000000', 'token' => 'visitor-token', 'status' => 'open', 'messages' => []], 201)]);

    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Maria', 'email' => 'maria@site.test']));
    $this->mock(ServerTrail::class)->shouldReceive('recent')->andReturn([
        ['type' => 'log', 'at' => now()->toIso8601String(), 'level' => 'error', 'message' => 'Falha ao salvar pedido'],
    ]);

    $this->postJson('support/widget/conversations', ['name' => 'Maria', 'email' => 'maria@site.test', 'message' => 'Não salva'])
        ->assertCreated()
        ->assertJsonPath('token', 'visitor-token');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://central.test/support/api/widget/conversations'
        && $request['visitor']['user_id'] === '7'
        && $request['server_events'][0]['message'] === 'Falha ao salvar pedido'
        && $request['message'] === 'Não salva');
});

it('passes the visitor token along', function () {
    Http::fake(['central.test/*' => Http::response(['id' => '01jz0000000000000000000000', 'status' => 'open', 'messages' => []])]);

    $this->getJson('support/widget/conversations/01jz0000000000000000000000', ['X-Support-Token' => 'visitor-token'])->assertOk();

    Http::assertSent(fn (Request $request) => $request->header('X-Support-Token') === ['visitor-token']);
});

it('says unavailable when the central is down or refuses the key', function (int $status) {
    Http::fake(['central.test/*' => Http::response(['message' => 'no'], $status)]);

    $this->getJson('support/widget/conversations/01jz0000000000000000000000', ['X-Support-Token' => 'visitor-token'])
        ->assertStatus(503)
        ->assertJsonPath('message', __('rastro::rastro.unavailable'));
})->with([502, 401]);

it('says unavailable without the central address', function () {
    config()->set('rastro.url', '');
    Http::fake();

    $this->getJson('support/widget/config')->assertStatus(503);
    Http::assertNothingSent();
});
