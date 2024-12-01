import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  build: {
    outDir: 'dist',
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'web/modules/custom/stripe_subscription/js/main.js')
      }
    }
  },
  server: {
    port: 3000,
    open: true
  }
});