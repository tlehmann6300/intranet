import { defineConfig } from 'vite';
import { resolve } from 'path';

/**
 * Vite configuration for the Intranet frontend assets.
 *
 * Features:
 *  - Compiles and minifies CSS (Tailwind) and JavaScript
 *  - Content-hash fingerprinting for cache-busting
 *  - Generates an asset manifest (`assets/dist/.vite/manifest.json`) that
 *    PHP can read via the `asset()` helper to serve versioned files
 *
 * Build:    npm run build
 * Dev mode: npm run dev   (hot-reload, no fingerprinting)
 *
 * NOTE: Tailwind is still compiled separately via `npm run build:css` for
 * backward compatibility with the existing `assets/css/tailwind.css` path.
 * As templates are gradually migrated to reference `assets/dist/` outputs,
 * the legacy Tailwind build can be removed.
 */
export default defineConfig(({ command }) => ({
  // Resolve project root so Vite finds relative imports
  root: resolve(__dirname),

  build: {
    // Output directory – kept inside assets/ so the web server serves it
    outDir: 'assets/dist',
    emptyOutDir: true,

    // Write a manifest so PHP can map logical names → hashed file names
    manifest: true,

    rollupOptions: {
      input: {
        // Main application bundle
        app: resolve(__dirname, 'assets/js/app.js'),
      },
    },

    // Minify in production; readable output during development builds
    minify: command === 'build' ? 'esbuild' : false,

    // Inline assets smaller than 4 kB as base64 data URIs
    assetsInlineLimit: 4096,

    // Generate source maps for production error tracing
    sourcemap: command !== 'build',
  },

  // Development server proxies PHP requests to the running web server
  server: {
    // Adjust the port to match your local setup
    port: 5173,
    strictPort: false,
    proxy: {
      // Forward everything that is not a Vite HMR request to PHP-FPM / Apache
      '/pages': { target: 'http://localhost:8080', changeOrigin: true },
      '/api':   { target: 'http://localhost:8080', changeOrigin: true },
      '/auth':  { target: 'http://localhost:8080', changeOrigin: true },
    },
  },

  // Resolve aliases for cleaner imports inside JS files
  resolve: {
    alias: {
      '@': resolve(__dirname, 'assets/js'),
      '@css': resolve(__dirname, 'assets/css'),
    },
  },
}));
