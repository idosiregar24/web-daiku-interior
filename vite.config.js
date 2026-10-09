import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Sprint 20 — the public company profile has its own small CSS/JS
            // (Blade, no React): resources/views/site/layouts/main.blade.php.
            input: ['resources/js/app.tsx', 'resources/css/site.css', 'resources/js/site.ts'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
});
