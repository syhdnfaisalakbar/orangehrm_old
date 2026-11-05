// eslint-disable-next-line @typescript-eslint/no-var-requires
const path = require('path');
const DumpBuildTimestampPlugin = require('./scripts/plugins/DumpBuildTimestampPlugin');

module.exports = {
  // --- KONFIGURASI DEV SERVER & PROXY (DITAMBAHKAN) ---
  devServer: {
    proxy: {
      '/acumportal': {
        target: 'https://asg.acumatica.com',
        changeOrigin: true,
        secure: false,
      },
    },
  },
  // ----------------------------------------------------

  css: {
    loaderOptions: {
      sass: {
        additionalData: `@import "@/core/styles";`,
      },
    },
    extract: true,
  },
  configureWebpack: {
    resolve: {
      alias: {
        '@ohrm/core': '@/core',
        '@ohrm/components': '@/core/components',
        // Paksa `mitt` ke build CommonJS-nya (dist/mitt.js).
        // Sebab: mitt 3.0.1 punya exports.require (CJS) dan exports.import (ESM).
        // Konsumen UMD (mis. @ohrm/oxd) me-require mitt lalu memanggilnya sebagai
        // fungsi; kalau webpack memilih varian ESM, yang didapat namespace
        // {default: fn} -> runtime error "o is not a function" -> halaman putih.
        'mitt$': path.resolve(__dirname, 'node_modules/mitt/dist/mitt.js'),
      },
    },
    plugins: [new DumpBuildTimestampPlugin()],
  },
  chainWebpack: (config) => {
    config.plugins.delete('html');
    config.plugins.delete('preload');
    config.plugins.delete('prefetch');
  },
  publicPath: '.',
  filenameHashing: false,
  runtimeCompiler: true,
};
