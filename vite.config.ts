import vue from '@vitejs/plugin-vue';
import type { Plugin } from 'vite';
import { defineConfig } from 'vite';

/**
 * One file: the CSS goes inside the JS, as a <style> it adds to the page.
 */
function cssInsideJs(): Plugin {
    return {
        name: 'rastro-css-inside-js',
        apply: 'build',
        enforce: 'post',
        generateBundle(_, bundle) {
            let css = '';

            for (const [name, file] of Object.entries(bundle)) {
                if (file.type === 'asset' && name.endsWith('.css')) {
                    css += String(file.source);
                    delete bundle[name];
                }
            }

            for (const file of Object.values(bundle)) {
                if (file.type === 'chunk' && file.isEntry && css) {
                    file.code =
                        `(function(){if(document.querySelector('style[data-rastro-widget]'))return;var s=document.createElement('style');s.setAttribute('data-rastro-widget','');s.textContent=${JSON.stringify(css)};document.head.appendChild(s);})();\n` +
                        file.code;
                }
            }
        },
    };
}

/*
 * The chat compiled with Vue inside (dist/rastro-widget.js), for sites that
 * do not compile the .vue themselves. `npm run build` after changing
 * RastroWidget.vue or embed.ts; the file is committed, so `composer
 * require` is enough on the site.
 */
export default defineConfig({
    plugins: [vue(), cssInsideJs()],
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
        __VUE_OPTIONS_API__: 'false',
        __VUE_PROD_DEVTOOLS__: 'false',
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        cssCodeSplit: false,
        lib: {
            entry: 'resources/js/embed.ts',
            name: 'RastroWidgetBundle',
            formats: ['iife'],
            fileName: () => 'rastro-widget.js',
            cssFileName: 'rastro-widget',
        },
    },
});
