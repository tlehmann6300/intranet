import { defineConfig } from 'vite';
import path from 'path';

/**
 * Vite configuration for IBC Intranet
 *
 * Build outputs (Vite 5+):
 *   public/assets/.vite/manifest.json  – Asset manifest (when manifest:true; default sub-path in Vite 5+)
 *   public/assets/js/app.[hash].js     – Application JS, minified
 *   public/assets/css/styles.[hash].css – Tailwind CSS, purged & minified
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
    // Vite 5+ places the manifest at outDir/.vite/manifest.json by default
    manifest: true,

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
