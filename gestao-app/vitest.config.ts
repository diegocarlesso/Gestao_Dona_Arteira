import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/js/test-setup.ts'],
        // Testes de componente ficam ao lado do componente.
        // Páginas Inertia são cobertas por teste de feature no backend
        // (assertInertia) — ver docs/06-Frontend §7.
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
    },
});
