import { defineConfig } from 'vite';
import path from 'path';

/**
 * Vite configuration for IBC Intranet
 *
 * Build outputs:
 *   public/assets/css/app.[hash].css   – Tailwind + theme CSS, purged & minified
 *   public/assets/js/app.[hash].js     – Application JS, minified
 *   public/assets/manifest.json        – Asset manifest consumed by the PHP
 *                                         asset() helper for cache-busting
 *
 * Development:
 *   npm run dev    – HMR dev server on http://localhost:5173
 *   npm run build  – Production build with hashed filenames
 */
export default defineConfig({
  root: '.',

  build: {
    outDir: 'public/assets',
    emptyOutDir: true,
    manifest: true,          // Generates public/assets/.vite/manifest.json

    rollupOptions: {
      input: {
        app: path.resolve(__dirname, 'assets/js/app.js'),
        styles: path.resolve(__dirname, 'assets/css/tailwind.src.css'),
      },
    },

    // Minification
    minify: 'esbuild',

    // CSS code splitting
    cssCodeSplit: true,
  },

  css: {
    postcss: {
      plugins: [],
    },
  },

  server: {
    port: 5173,
    strictPort: true,
    // Proxy API requests to the PHP backend during development
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/pages': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
});
