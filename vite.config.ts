import { fileURLToPath, URL } from 'node:url';
import { resolve } from 'path';

import { defineConfig, splitVendorChunkPlugin } from 'vite';
import vue from '@vitejs/plugin-vue';

// https://vitejs.dev/config/
export default defineConfig({
    plugins: [
        vue(),
        splitVendorChunkPlugin(),
    ],
    build: {
        manifest: true, // Explicitly enable manifest generation
        rollupOptions: {
            input: {
                settings: resolve(__dirname, './src/main.ts'),
                manager: resolve(__dirname, './src/manager.ts'),
            },
            output: {
                assetFileNames: (assetInfo) => {
                    let extType = assetInfo.name.split('.').at(1);
                    if (/png|jpe?g|svg|gif|tiff|bmp|ico/i.test(extType)) {
                        extType = 'img';
                    }
                    return `assets/${extType}/[name]-[hash][extname]`;
                },
                chunkFileNames: 'assets/js/[name]-[hash].js',
                entryFileNames: 'assets/js/[name]-[hash].js',
            },
        },
        sourcemap: true,
        minify: false,
    },
  resolve: {
      alias: {
          '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
  }
})
