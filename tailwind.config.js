// Tailwind 3.4: the engine the design was drawn with (it used to run in the browser from the
// Play CDN), so every utility looks as it did. Classes are only found in these files: build
// a class name from pieces and it will be missing from the CSS.
/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/**/*.php',
        // Livewire's pagination links (the Invoices list) — docker/app/Dockerfile copies them in.
        './vendor/livewire/livewire/src/Features/SupportPagination/views/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                paper: '#F2EEE3',
                surface: '#FCFBF6',
                ink: '#17150F',
                inkpanel: '#1C1A14',
                muted: '#6E6757',
                faint: '#9A9384',
                line: '#DAD3C2',
                hair: '#E6E0D1',
                stamp: '#BE3A22',
                ledger: '#1F6B4A',
                amber: '#A9761A',
            },
            fontFamily: {
                display: ['"Fraunces Variable"', 'Fraunces', 'Georgia', 'serif'],
                sans: ['"Spline Sans Variable"', '"Spline Sans"', 'system-ui', 'sans-serif'],
                mono: ['"Spline Sans Mono Variable"', '"Spline Sans Mono"', 'ui-monospace', 'monospace'],
            },
        },
    },
    plugins: [],
};
