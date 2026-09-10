import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
    build: {
        outDir: 'assets/build/core',
        emptyOutDir: true,
        rollupOptions: {
            input: {
                'folderfolio': resolve(__dirname, 'assets/src/core/folder-tree.ts'),
                'media-modal': resolve(__dirname, 'assets/src/core/media-modal.ts'),
                'bulk-actions': resolve(__dirname, 'assets/src/core/bulk-actions.ts'),
                'upload-integration': resolve(__dirname, 'assets/src/core/upload-integration.ts'),
                'api': resolve(__dirname, 'assets/src/core/api.ts'),
                'media-library-integration': resolve(
                    __dirname,
                    'assets/src/core/media-library-integration.ts',
                ),
            },
            output: {
                dir: 'assets/build/core',
                entryFileNames: '[name].js',
                chunkFileNames: '[name].[hash].js',
                assetFileNames: '[name].[ext]',
            },
        },
    },
    resolve: {
        alias: {
            '@': resolve(__dirname, 'assets/src'),
        },
    },
});
