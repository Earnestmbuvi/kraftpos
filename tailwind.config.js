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
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50: '#FFF4EE',
                    100: '#FDEBE3',
                    200: '#FAD3C0',
                    500: '#F26B3A',
                    600: '#E5551F',
                    700: '#BF4318',
                },
                ink: '#0B1220',
            },
        },
    },

    plugins: [forms],
};
