<?php

namespace Callcocam\Rastro\Tests;

use Callcocam\Rastro\RastroServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RastroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('cache.default', 'array');
        $config->set('app.locale', 'pt_BR');
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $config->set('rastro.url', 'https://central.test/support/api');
        $config->set('rastro.key', 'central-key');
    }
}
