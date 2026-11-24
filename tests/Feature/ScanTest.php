<?php

use Callcocam\Rastro\Scan\ProjectScanner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * A throwaway app tree inside the testbench base path: an app/ folder with a
 * controller that validates, a lang/ folder, a page — and the things that
 * must never be read: .env, config/, storage/ and a secret pasted in code.
 */
beforeEach(function () {
    $this->base = base_path('scan-fixture');
    // A fake live key, built at runtime so the file itself holds no key-shaped string.
    $fakeKey = 'sk_'.'live_'.str_repeat('abcdefgh', 3);
    File::deleteDirectory($this->base);

    File::ensureDirectoryExists("{$this->base}/app/Http");
    File::put("{$this->base}/app/Http/SignupController.php", str_replace('FAKE_KEY', $fakeKey, <<<'PHP'
        <?php
        class SignupController {
            public function store($request) {
                $request->validate(['nome' => 'required|max:80', 'senha' => ['required', 'min:10']]);
                $stripe = 'FAKE_KEY';
            }
        }
        PHP));
    File::ensureDirectoryExists("{$this->base}/app/config");
    File::put("{$this->base}/app/config/secret.php", "<?php\n\$x = ['rules' => fn () => 1]; // validate( ['senha_mestra' => 'x'])");
    File::put("{$this->base}/.env", "APP_KEY=base64:SEGREDO\nDB_PASSWORD=SEGREDO");
    File::ensureDirectoryExists("{$this->base}/config");
    File::put("{$this->base}/config/services.php", "<?php return ['key' => 'SEGREDO'];");
    File::ensureDirectoryExists("{$this->base}/resources/js/pages/auth");
    File::put("{$this->base}/resources/js/pages/auth/Register.vue", '<template>SEGREDO-DO-VUE</template>');
    File::ensureDirectoryExists("{$this->base}/lang/pt_BR");
    File::put("{$this->base}/lang/pt_BR/auth.php", "<?php return ['register' => ['title' => 'Criar sua conta'], 'token' => '{$fakeKey}'];");

    $this->app->setBasePath($this->base);
    $this->app->useLangPath("{$this->base}/lang");

    Route::middleware('web')->post('/cadastro', fn () => 'ok')->name('signup');
    Route::middleware('web')->get('/support/inbox/x', fn () => 'ok');
    Route::middleware('api')->get('/api/interno', fn () => 'ok');
});

afterEach(fn () => File::deleteDirectory($this->base));

it('reads routes, validation, page names and translations — and nothing secret', function () {
    $text = app(ProjectScanner::class)->render('pt_BR');

    expect($text)
        ->toContain('POST /cadastro [signup]')
        ->toContain("'senha' => ['required', 'min:10']")
        ->toContain('auth/Register.vue')
        ->toContain('auth.register.title: Criar sua conta')
        ->not->toContain('SEGREDO')
        ->not->toContain('sk_live')
        ->not->toContain('senha_mestra')
        ->not->toContain('/support/inbox')
        ->not->toContain('/api/interno');
});

it('prints what would be sent with --show and sends nothing', function () {
    Http::fake();

    $this->artisan('rastro:scan --show --locale=pt_BR')
        ->expectsOutputToContain('POST /cadastro')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('sends the reading to the central', function () {
    Http::fake(['central.test/*' => Http::response(['queued' => true], 202)]);

    $this->artisan('rastro:scan --locale=pt_BR')
        ->expectsOutput(__('rastro::rastro.scan_sent'))
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://central.test/support/api/scan'
        && $request['locale'] === 'pt_BR'
        && str_contains($request['text'], 'POST /cadastro')
        && ! str_contains($request['text'], 'SEGREDO'));
});

it('fails clearly when the central cannot be reached', function () {
    Http::fake(['central.test/*' => Http::response([], 500)]);

    $this->artisan('rastro:scan')->assertFailed();
});
