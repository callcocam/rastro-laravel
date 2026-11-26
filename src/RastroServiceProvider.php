<?php

namespace Callcocam\Rastro;

use Callcocam\Rastro\Console\HelpGuide;
use Callcocam\Rastro\Console\HelpPush;
use Callcocam\Rastro\Console\ProjectScan;
use Callcocam\Rastro\Context\RecordLogErrors;
use Callcocam\Rastro\Context\RecordServerTrail;
use Callcocam\Rastro\Context\Sanitizer;
use Callcocam\Rastro\Context\ServerTrail;
use Callcocam\Rastro\Http\WidgetRelayController;
use Callcocam\Rastro\Http\WidgetScriptController;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The support chat for a site: the widget's endpoints (relayed to the
 * support central with the site's key), the recording of what the visitor
 * did, and the project reading for the central's AI. No tables, no screens.
 */
class RastroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rastro.php', 'rastro');

        $this->app->singleton(ServerTrail::class);
        $this->app->bind(Sanitizer::class, fn () => Sanitizer::fromConfig());
        $this->app->bind(Central::class, fn () => Central::fromConfig());
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'rastro');

        // <x-rastro::widget />: the compiled chat, for Blade, Filament and React sites.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rastro');

        if ($this->app['config']->get('rastro.routes', true)) {
            $this->registerRoutes();
        }

        $this->registerRecording();

        if ($this->app->runningInConsole()) {
            $this->commands([ProjectScan::class, HelpGuide::class, HelpPush::class]);

            $this->publishes([__DIR__.'/../config/rastro.php' => config_path('rastro.php')], 'rastro-config');

            // The Vue widget, compiled by the site's own Vite build.
            $this->publishes([__DIR__.'/../resources/js/RastroWidget.vue' => resource_path('js/components/rastro/RastroWidget.vue')], 'rastro-widget');

            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/rastro')], 'rastro-lang');
        }
    }

    /**
     * Same origin for the browser (the visitor's session and login go along);
     * this server relays to the central.
     */
    protected function registerRoutes(): void
    {
        $config = $this->app['config'];

        // The compiled chat: no session or throttle, it is a static file.
        $script = Route::get(trim((string) $config->get('rastro.widget.prefix', 'support/widget'), '/').'/rastro-widget.js', WidgetScriptController::class)
            ->name($config->get('rastro.widget.name', 'rastro.widget').'.script');

        if ($domain = $config->get('rastro.widget.domain')) {
            $script->domain($domain);
        }

        Route::group(array_filter([
            'domain' => $config->get('rastro.widget.domain') ?: null,
            'prefix' => $config->get('rastro.widget.prefix', 'support/widget'),
            'middleware' => (array) $config->get('rastro.widget.middleware', ['web', 'throttle:60,1']),
            'as' => $config->get('rastro.widget.name', 'rastro.widget').'.',
        ]), function () {
            Route::get('/config', [WidgetRelayController::class, 'config'])->name('config');
            Route::post('/conversations', [WidgetRelayController::class, 'store'])->name('store');
            Route::get('/conversations/{conversation}', [WidgetRelayController::class, 'show'])->name('show')->whereUlid('conversation');
            Route::post('/conversations/{conversation}/messages', [WidgetRelayController::class, 'message'])->name('message')->whereUlid('conversation');
            Route::post('/conversations/{conversation}/handoff', [WidgetRelayController::class, 'handoff'])->name('handoff')->whereUlid('conversation');
            Route::post('/conversations/{conversation}/close', [WidgetRelayController::class, 'close'])->name('close')->whereUlid('conversation');
            Route::post('/conversations/{conversation}/rating', [WidgetRelayController::class, 'rate'])->name('rating')->whereUlid('conversation');
            Route::post('/help/{node}/feedback', [WidgetRelayController::class, 'feedback'])->name('help.feedback')->whereUlid('node');
        });
    }

    /**
     * A middleware on `web` notes validation errors and failed requests, and
     * a log listener notes errors, per session — sent along when the visitor
     * asks for help.
     */
    protected function registerRecording(): void
    {
        $config = $this->app['config'];

        if (! $config->get('rastro.context.enabled', true)) {
            return;
        }

        if ($config->get('rastro.context.middleware', true)) {
            $this->app->make(Router::class)->pushMiddlewareToGroup('web', RecordServerTrail::class);
        }

        if ($config->get('rastro.context.log_level')) {
            Event::listen(MessageLogged::class, RecordLogErrors::class);
        }
    }
}
