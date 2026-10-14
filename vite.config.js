import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    const { APP_URL, QUICKBOOKS_REDIRECT_URI, DEV_BROWSER_HOST_IP } = loadEnv(mode, process.cwd(), [
        'APP_URL',
        'QUICKBOOKS_REDIRECT_URI',
        'DEV_',
    ]);
    const allowedOrigins = new Set();

    if (APP_URL) {
        allowedOrigins.add(new URL(APP_URL).origin);
    }

    if (QUICKBOOKS_REDIRECT_URI) {
        const callback = new URL(QUICKBOOKS_REDIRECT_URI);

        if (['localhost', '127.0.0.1', '[::1]'].includes(callback.hostname)) {
            allowedOrigins.add(callback.origin);
        }
    }

    return {
        plugins: [
            laravel({
                input: 'resources/js/app.ts',
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
        server: {
            cors: allowedOrigins.size > 0 ? { origin: [...allowedOrigins] } : undefined,
            hmr: DEV_BROWSER_HOST_IP ? { host: DEV_BROWSER_HOST_IP } : undefined,
        },
    };
});
