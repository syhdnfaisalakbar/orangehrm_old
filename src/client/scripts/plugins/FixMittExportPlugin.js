/**
 * Webpack 5 plugin: Fix mitt module export untuk @ohrm/oxd UMD.
 *
 * Problem: webpack wrap CommonJS `module.exports = fn` jadi
 * `{__esModule: true, default: fn}`. @ohrm/oxd UMD panggil
 * `require("mitt")` expecting raw function, bukan wrapper object.
 *
 * Fix: replace module wrapper dengan direct assignment `t = function(){...}`.
 */
class FixMittExportPlugin {
  apply(compiler) {
    const NAME = 'FixMittExportPlugin';
    const STAGE = 2150; // PROCESS_ASSETS_STAGE_OPTIMIZE_TRANSFER

    compiler.hooks.thisCompilation.tap(NAME, (compilation) => {
      compilation.hooks.processAssets.tap(
        { name: NAME, stage: STAGE },
        (assets) => {
          for (const [filename, asset] of Object.entries(assets)) {
            if (!filename.includes('chunk-vendors')) continue;

            let source = asset.source();
            const origLength = source.length;

            // Pattern 1: mitt dengan module ID (:7380:function etc)
            // Cari pola: ID:function(e,t,n){"use strict";function r(e){return{all:e=e||new Map,...
            // Diikuti: n.r(t),n.d(t,{default:function(){return r}}),n.dn(r)}
            source = source.replace(
              /(\d+:function\([^)]+\)\{"use strict";)(function r\(e\)\{return\{all:e=e\|\|new Map,on:function\([^)]+\)\{[^}]*\},off:function\([^)]+\)\{[^}]*\},emit:function\([^)]+\)\{[^}]*\}\}\})(n\.r\(t\),n\.d\(t,\{default:function\(\)\{return r\}\}\),n\.dn\(r\)\})/g,
              (match, prefix, fnBody, suffix) => {
                console.log(`[${NAME}] Fixing mitt module: ${prefix.substring(0, 10)}...`);
                return `${prefix}${fnBody.replace('function r', 't=')}`;
              }
            );

            if (source.length !== origLength) {
              const { RawSource } = compiler.webpack?.sources || require('webpack').sources;
              compilation.updateAsset(filename, new RawSource(source));
              console.log(`[${NAME}] ✓ Fixed mitt export in ${filename}`);
            } else {
              console.log(`[${NAME}] ⚠ No mitt pattern found, trying backup...`);
              // Backup: cari mitt function signature bebas
              source = asset.source();
              const backupResult = source.replace(
                /(\d+:function\([^)]+\)\{"use strict";)(function r\(e\)\{return\{all:e=e\|\|new Map[^}]*?\}\})(n\.r\(t\),n\.d\(t,\{default:function\(\)\{return r\}\}\),n\.dn\(r\)\})/g,
                (match, prefix, fnBody, suffix) => {
                  console.log(`[${NAME}] Backup fix: ${prefix.substring(0, 10)}...`);
                  return `${prefix}${fnBody.replace('function r', 't=')}`;
                }
              );
              if (backupResult !== source) {
                const { RawSource } = compiler.webpack?.sources || require('webpack').sources;
                compilation.updateAsset(filename, new RawSource(backupResult));
                console.log(`[${NAME}] ✓ Fixed mitt export (backup) in ${filename}`);
              } else {
                console.log(`[${NAME}] ✗ Could not find mitt module`);
              }
            }
          }
        }
      );
    });
  }
}

module.exports = FixMittExportPlugin;
