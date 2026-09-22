import {
    defineConfig,
    loadEnv,
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    // Independent of APP_URL (which stays pointed at the Cloudflare tunnel
    // domain for the app itself): the tunnel has no ingress rule for port
    // 5173, so deriving the Vite dev host from APP_URL breaks local dev.
    // Defaults to localhost; set VITE_DEV_HOST to test over the LAN/tunnel.
    const appHost = env.VITE_DEV_HOST || 'localhost';

    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                ],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            cors: true,
            hmr: {
                host: appHost,
                port: 5173,
            },
            watch: {
                ignored: [
                    '**/.agents/**',
                    '**/.claude/**',
                    '**/.cursor/**',
                    '**/.junie/**',
                    '**/storage/framework/views/**',
                    '**/vendor/**',
                ],
            },
        },
    };
});
