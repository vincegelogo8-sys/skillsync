import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // ISAT U blue: replaces Tailwind's default indigo, so every indigo-* class follows the theme.
                indigo: {
                    50: '#eaf0ff', 100: '#dbe6ff', 200: '#c3d3f6', 300: '#9fb6ea', 400: '#5f83d6',
                    500: '#2f58b8', 600: '#1a3a9c', 700: '#12296f', 800: '#0f2461', 900: '#0b1f55', 950: '#071544',
                },
                // ISAT U gold, available as gold-50 ... gold-700.
                gold: {
                    50: '#fffbea', 100: '#fff3c4', 200: '#ffe58a', 300: '#ffd54a', 400: '#f9c315',
                    500: '#f5b700', 600: '#c98f00', 700: '#8a5f00',
                },
            },
        },
    },

    plugins: [forms],
};