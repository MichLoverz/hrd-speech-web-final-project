import forms from '@tailwindcss/forms';

/**
 * HireNote design tokens. See docs/design-system.md for the rationale.
 * Metaphor: an interviewer's paper notes — paper, ink, pencil, highlighter.
 */

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/**/*.php',
    ],

    theme: {
        fontFamily: {
            sans: ['"IBM Plex Sans"', 'system-ui', 'Segoe UI', 'sans-serif'],
            transcript: ['"Courier Prime"', '"Courier New"', 'Courier', 'monospace'],
        },
        fontSize: {
            xs: ['13px', { lineHeight: '1.45' }],
            sm: ['14px', { lineHeight: '1.5' }],
            base: ['15px', { lineHeight: '1.55' }],
            md: ['17px', { lineHeight: '1.5' }],
            lg: ['21px', { lineHeight: '1.35' }],
            xl: ['26px', { lineHeight: '1.25' }],
            '2xl': ['33px', { lineHeight: '1.15' }],
        },
        borderRadius: {
            none: '0',
            DEFAULT: '3px',
            full: '9999px',
        },
        extend: {
            colors: {
                paper: '#F4F5F1',
                sheet: '#FFFFFF',
                rule: '#DEE1DA',
                ink: {
                    DEFAULT: '#1F2B3A',
                    soft: '#3D4A5B',
                },
                pencil: {
                    DEFAULT: '#7C838C',
                    light: '#B4B9C0',
                },
                highlighter: '#F5E663',
                stamp: '#2E6B4E',
                danger: '#9E3223',
            },
            maxWidth: {
                sheet: '1080px',
                prose: '68ch',
            },
        },
    },

    plugins: [forms({ strategy: 'class' })],
};
