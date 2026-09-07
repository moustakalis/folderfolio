module.exports = {
  entryPoints: [
    'assets/src/core/folder-tree.ts',
    'assets/src/core/api.ts',
  ],
  outdir: 'assets/build/core',
  bundle: true,
  format: 'esm',
  minify: true,
  sourcemap: true,
  target: 'es2020',
};
