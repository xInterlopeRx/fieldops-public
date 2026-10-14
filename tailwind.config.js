import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                brand: ['Manrope', 'Avenir Next', 'Segoe UI', 'Arial', 'sans-serif'],
                sans: ['Inter', 'Segoe UI', 'Roboto', 'Helvetica', 'Arial', 'sans-serif'],
                mono: [
                    'JetBrains Mono',
                    'Cascadia Code',
                    'SFMono-Regular',
                    'Consolas',
                    'Liberation Mono',
                    'monospace',
                ],
            },
            borderRadius: {
                md: '6px',
                lg: '8px',
                xl: '8px',
                '2xl': '8px',
                '3xl': '8px',
            },
            colors: {
                fieldops: {
                    blue: '#3B82F6',
                    'blue-dark': '#2563EB',
                    'blue-strong': '#1D4ED8',
                    'blue-light': '#DBEAFE',
                    orange: '#F97316',
                    navy: '#08132E',
                    success: '#2E8B57',
                    'success-dark': '#236B43',
                    'success-light': '#E8F5EC',
                    warning: '#D97706',
                    'warning-dark': '#B45309',
                    'warning-light': '#FEF3C7',
                    danger: '#DC2626',
                    'danger-dark': '#B91C1C',
                    'danger-light': '#FEE2E2',
                    magenta: '#C03A7A',
                    'magenta-dark': '#972D60',
                    'magenta-light': '#F9E6F0',
                    slate: '#475569',
                    'slate-dark': '#334155',
                    'slate-light': '#E2E8F0',
                    border: '#94A3B8',
                    'border-strong': '#64748B',
                    archive: '#475569',
                    background: '#F8FAFC',
                    panel: '#FFFFFF',
                    'tab-inactive': '#E2E8F0',
                },
            },
        },
    },

    plugins: [forms],
};
