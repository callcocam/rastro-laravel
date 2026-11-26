<?php

namespace Callcocam\Rastro;

use Illuminate\Support\Str;

/**
 * The chat compiled with Vue inside (dist/rastro-widget.js), for sites that
 * do not build RastroWidget.vue themselves: Blade, Filament, React. Served
 * by this site (WidgetScriptController), so it updates with the package.
 */
class Widget
{
    public static function path(): string
    {
        return dirname(__DIR__).'/dist/rastro-widget.js';
    }

    /**
     * Changes with the file: the browser keeps it until the next update.
     */
    public static function version(): string
    {
        static $versions = [];

        $path = self::path();

        return $versions[$path] ??= is_file($path) ? substr((string) md5_file($path), 0, 12) : '0';
    }

    public static function scriptUrl(): string
    {
        return route('rastro.widget.script', ['v' => self::version()]);
    }

    /**
     * Where the chat talks to: this site's relay (support/widget).
     */
    public static function endpoint(): string
    {
        return Str::beforeLast(route('rastro.widget.config'), '/config');
    }
}
