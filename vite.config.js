import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.scss', 'resources/js/app.js', 'resources/js/theme.js',
                // Halaman /draft (evaluasi desain & fitur baru)
                'resources/css/draft.scss', 'resources/js/draft/ringkasan.js',
                'resources/js/draft/mesin.js', 'resources/js/draft/mesin-profil.js', 'resources/js/draft/masalah.js', 'resources/js/draft/waktu.js', 'resources/js/draft/produk.js', 'resources/js/draft/operator.js',
                'resources/css/draft2.scss', 'resources/js/draft2/ringkasan.js',
                'resources/css/draft3.scss', 'resources/js/draft3/ringkasan.js',
                'resources/css/draft4.scss', 'resources/js/draft4/ringkasan.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
