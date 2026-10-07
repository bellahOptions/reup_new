import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],

    server: {
        // Bind explicitly to IPv4 loopback.
        //
        // Without this, Vite listens on `localhost`, which on this machine
        // resolves to IPv6 `[::1]` only. `php artisan serve` is reached over
        // IPv4 (127.0.0.1), so the browser could not fetch the dev assets and
        // every page rendered unstyled with no visible error — the stylesheet
        // is injected by JavaScript, so a failed fetch produces no 404.
        host: '127.0.0.1',
        port: 5173,
        strictPort: false,

        // Poll rather than use native filesystem events: more reliable on
        // Windows and inside VMs/containers, at a small CPU cost.
        watch: {
            usePolling: true,
            interval: 300,
        },

        hmr: {
            host: '127.0.0.1',
        },
    },

    build: {
        sourcemap: false,
    },
});
