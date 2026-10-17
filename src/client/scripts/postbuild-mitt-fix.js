/**
 * Post-build fix: mitt module export in chunk-vendors.js
 *
 * Two patterns:
 * 1. Old CJS: webpack wraps `module.exports = fn` as `{__esModule: true, default: fn}`
 *    → Direct assignment: t=function(e){return{...}}
 * 2. New ESM (mitt.mjs): webpack returns namespace `{default: fn}` for ESM modules
 *    → Add .default to the require: __webpack_require__(...).default
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const VENDORS_PATH = path.join(__dirname, '..', '..', '..', 'web', 'dist', 'js', 'chunk-vendors.js');

if (!fs.existsSync(VENDORS_PATH)) {
  console.log('[postbuild-mitt-fix] chunk-vendors.js not found, skipping');
  process.exit(0);
}

let source = fs.readFileSync(VENDORS_PATH, 'utf8');
let fixed = source;
// Fix 1: Old CJS mitt pattern (webpack wraps module.exports as {default: fn})
// → Use e.exports=function (NOT t=function, because t is a local reference)
fixed = fixed.replace(
  /(\d+:function\([^)]+\)\{"use strict";)(function r\(e\)\{return\{)(all:e=e\|\|new Map)(.*?)(n\.r\(t\),n\.d\(t,\{default:function\(\)\{return r\}\}\),n\.dn\(r\))/g,
  (match, prefix, fnStart, mapInit, fnBody) =>
    `${prefix}e.exports=${fnStart}${mapInit}${fnBody}`
);

// Also catch the direct t=function pattern (no n.r/n.d wrapper)
fixed = fixed.replace(
  /(\d+:function\(e,t,n\)\{"use strict";)t=function r\(e\)\{return\{all:e=e\|\|new Map/g,
  (match, prefix) =>
    `${prefix}e.exports=function r(e){return{all:e=e||new Map`
);

// Fix 2: New ESM mitt.mjs pattern (webpack ESM interop returns {default: fn})
// The OXD UMD factory receives mitt as parameter n3 and expects a function.
// Add .default to unwrap the ESM default export.
// Note: quotes are escaped (\\\") because the code is inside eval("...")
fixed = fixed.replace(
  /(__webpack_require__\(\/\*! mitt \*\/ \\"\.\/node_modules\/mitt\/dist\/mitt\.mjs\\"\))/g,
  '$1.default'
);

if (fixed !== source) {
  fs.writeFileSync(VENDORS_PATH, fixed, 'utf8');
  const diffSize = Math.abs(source.length - fixed.length);
  console.log(`[postbuild-mitt-fix] ✓ Fixed mitt export in chunk-vendors.js (${diffSize} bytes changed)`);
} else {
  console.log('[postbuild-mitt-fix] ⚠ No mitt pattern found');
}
