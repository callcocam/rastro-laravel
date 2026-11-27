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
 * It puts <x-rastro::widget /> at the end of every page of the panel. With
 * ->launcher(false) there is no floating button: open the chat from a button
 * of the panel with `window.Rastro.open()`.
 */
class RastroPlugin implements Plugin
{
    protected bool $launcher = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public function launcher(bool $show = true): static
    {
        $this->launcher = $show;

        return $this;
    }

    public function getId(): string
    {
        return 'rastro';
    }

    public function register(Panel $panel): void
    {
        $panel->renderHook(PanelsRenderHook::BODY_END, fn (): string => Blade::render('<x-rastro::widget :launcher="$launcher" />', ['launcher' => $this->launcher]));
    }

    public function boot(Panel $panel): void {}
}
