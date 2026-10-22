/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Le front vit dans assets/ et sort dans public/build/, que nginx sert.
// En dev, Vite sert le front sur :5173 et relaie /api vers nginx.
export default defineConfig(({ command }) => ({
  root: 'assets',
  base: command === 'build' ? '/build/' : '/',
  plugins: [react(), tailwindcss()],
  build: {
    outDir: '../public/build',
    emptyOutDir: true,
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    // Tout chemin commençant par /api part vers Symfony : aucun dossier assets/api
    // (voir assets/viteProxy.test.ts).
    proxy: {
      // Garder le Host du navigateur : « Mes clés » compare Origin et Host.
      '/api': { target: process.env.VITE_API_PROXY ?? 'http://localhost:8080', changeOrigin: false },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['**/*.test.{ts,tsx}'],
    setupFiles: ['./test-setup.ts'],
    restoreMocks: true,
  },
}))
