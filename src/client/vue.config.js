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
