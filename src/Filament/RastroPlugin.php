<?php

namespace Callcocam\Rastro\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

/**
 * The Rastro chat on a Filament panel (v3 and v4), with no Blade to edit:
 *
 *   ->plugin(\Callcocam\Rastro\Filament\RastroPlugin::make())
 *
 * It puts <x-rastro::widget /> at the end of every page of the panel.
 */
class RastroPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'rastro';
    }

    public function register(Panel $panel): void
    {
        $panel->renderHook(PanelsRenderHook::BODY_END, fn (): string => Blade::render('<x-rastro::widget />'));
    }

    public function boot(Panel $panel): void {}
}
