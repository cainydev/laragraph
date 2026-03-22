import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

// Run from repo root: npm run build --prefix workbench
// Vite root = workbench/, so all paths are relative to workbench/.
export default defineConfig({
    server: {
        host: 'localhost',
        port: 5173,
        hmr: { host: 'localhost' },
    },
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/js/app.tsx', 'resources/css/app.css'],
            refresh: true,
            publicDirectory: 'public',
        }),
        react(),
    ],
    define: {
        'import.meta.env.VITE_REVERB_APP_KEY': JSON.stringify('laragraph-key'),
        'import.meta.env.VITE_REVERB_HOST': JSON.stringify('localhost'),
        'import.meta.env.VITE_REVERB_PORT': JSON.stringify('8080'),
        'import.meta.env.VITE_REVERB_SCHEME': JSON.stringify('http'),
    },
})
