import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// The UI's CSS (Tailwind 3.4 through PostCSS, see tailwind.config.js) and its fonts are built
// into public/build: a page loads nothing from the internet.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
