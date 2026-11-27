import { createApp } from 'vue';
import type { App } from 'vue';
import RastroWidget from './RastroWidget.vue';

/*
 * The chat with Vue inside, for sites that do not build it themselves:
 * Blade and Filament (<x-rastro::widget />, RastroPlugin), React, or a site
 * without Laravel. On its own it mounts from the <script> tag's data-*:
 *
 *   <script src=".../rastro-widget.js" data-endpoint="/support/widget" defer></script>
 *       a Laravel site with rastro-laravel (same origin: session and login go along)
 *
 *   <script src="https://central/support/embed/rastro-widget.js" data-site="pk_…" defer></script>
 *       a site without Laravel, straight to the central with its public key
 *
 * With data-manual, nothing mounts until window.Rastro.mount({ … }) (React's
 * useEffect, for instance). With data-launcher="false" there is no floating
 * button: the site's own button calls window.Rastro.open().
 */

interface MountOptions {
    endpoint?: string;
    storageKey?: string;
    siteKey?: string;
    launcher?: boolean;
}

interface RastroGlobal {
    mount: (options?: MountOptions) => App;
    unmount: () => void;
    open: () => void;
    close: () => void;
    toggle: () => void;
}

declare global {
    interface Window {
        Rastro?: RastroGlobal;
    }
}

function boot(script: HTMLScriptElement | null): void {
    let mounted: { app: App; element: HTMLElement } | null = null;

    // SPA navigation (Livewire's wire:navigate, Turbo) swaps <body>: put the
    // chat back, still running, conversation and all.
    const reattach = () => {
        if (mounted && !mounted.element.isConnected) {
            document.body.appendChild(mounted.element);
        }
    };

    for (const name of ['livewire:navigated', 'turbo:load']) {
        document.addEventListener(name, reattach);
    }

    const rastro: RastroGlobal = {
        mount(options = {}) {
            if (mounted) {
                return mounted.app;
            }

            const element = document.createElement('div');
            element.setAttribute('data-rastro-widget', '');
            document.body.appendChild(element);

            const props = Object.fromEntries(
                Object.entries(options).filter(
                    ([, value]) => value !== undefined && value !== '',
                ),
            );
            const app = createApp(RastroWidget, props);
            app.mount(element);
            mounted = { app, element };

            return app;
        },

        unmount() {
            mounted?.app.unmount();
            mounted?.element.remove();
            mounted = null;
        },

        open: () => window.dispatchEvent(new Event('rastro:open')),
        close: () => window.dispatchEvent(new Event('rastro:close')),
        toggle: () => window.dispatchEvent(new Event('rastro:toggle')),
    };

    window.Rastro = rastro;

    if (!script || script.dataset.manual !== undefined) {
        return;
    }

    const siteKey = script.dataset.site || undefined;
    const endpoint =
        script.dataset.endpoint ||
        (siteKey ? `${new URL(script.src).origin}/support/embed` : undefined);
    const options: MountOptions = {
        endpoint,
        siteKey,
        storageKey: script.dataset.storageKey || undefined,
        launcher: script.dataset.launcher === 'false' ? false : undefined,
    };

    if (document.body) {
        rastro.mount(options);
    } else {
        document.addEventListener('DOMContentLoaded', () =>
            rastro.mount(options),
        );
    }
}

// Loaded twice (a page swap that re-runs scripts): the first one stays.
if (!window.Rastro) {
    boot(document.currentScript as HTMLScriptElement | null);
}
