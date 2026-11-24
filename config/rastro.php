<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Support central
    |--------------------------------------------------------------------------
    |
    | Your support central's API address and this site's key, both from the
    | central's settings. The visitor's browser talks only to this site; this
    | server relays the chat to the central, with the key.
    |
    */

    'url' => env('RASTRO_URL'), // e.g. https://minhaempresa.suporte.com.br/support/api
    'key' => env('RASTRO_KEY'),
    'timeout' => (int) env('RASTRO_TIMEOUT', 10),

    // Register the widget's endpoints. Off: register WidgetRelayController yourself.
    'routes' => (bool) env('RASTRO_ROUTES', true),

    'widget' => [
        'prefix' => env('RASTRO_WIDGET_PREFIX', 'support/widget'),
        'name' => 'rastro.widget',
        'domain' => env('RASTRO_WIDGET_DOMAIN'),
        'middleware' => ['web', 'throttle:60,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | What goes with a question
    |--------------------------------------------------------------------------
    |
    | The widget records the visitor's last screens, clicks, validation errors,
    | failed requests and JavaScript errors in the browser. Here, the server
    | side: a middleware on `web` notes validation errors and HTTP failures and
    | a log listener notes entries from `log_level` up, in the cache, per
    | session, for `minutes`. Typed values, passwords and tokens are never kept.
    |
    */

    'context' => [
        'enabled' => (bool) env('RASTRO_CONTEXT', true),
        'minutes' => (int) env('RASTRO_CONTEXT_MINUTES', 30),
        'max_events' => 40,
        'cache_store' => env('RASTRO_CONTEXT_CACHE'),
        'middleware' => (bool) env('RASTRO_CONTEXT_MIDDLEWARE', true),
        'log_level' => env('RASTRO_CONTEXT_LOG_LEVEL', 'error'),
        'hidden_keys' => ['password', 'pass', 'senha', 'token', 'secret', 'authorization', 'cookie', 'api_key', 'apikey', 'card', 'cartao', 'cvv', 'cpf', 'cnpj'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Project reading (php artisan rastro:scan)
    |--------------------------------------------------------------------------
    |
    | Reads, on this server, only: web routes, validation rules (Form Requests
    | and rule arrays under `paths`), the locale's translations and the page
    | names — never .env, config/, storage/, database/, vendor/, node_modules
    | or tests, not even when listed here — and sends it to the central to
    | draft the help. `--show` prints what would be sent.
    |
    */

    'scan' => [
        'paths' => ['app'],
        'pages_path' => 'resources/js/pages',
        'exclude_routes' => ['_*', 'support/*', '_ignition/*', '_debugbar/*', 'sanctum/*', 'livewire/*', 'telescope*', 'horizon*', 'pulse*', 'up', 'storage/*', 'broadcasting/*'],
        'exclude_pages' => ['support/*', '*/partials/*', '*/components/*'],
        'max_chars' => 120000,
    ],

];
