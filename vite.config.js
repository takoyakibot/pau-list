import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/propose.js', 'resources/js/proposal-shared.js', 'resources/js/home.js', 'resources/js/home-carousel.js', 'resources/js/owner-edit.js', 'resources/js/owner-logged-out.js', 'resources/js/owner-deleted.js', 'resources/js/creation-runner.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
