import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
// 1. Importa el plugin de Vue
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        // 2. Añade el plugin a la lista
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});