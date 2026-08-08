#!/usr/bin/env node
/**
 * Copy MapLibre's worker into public/ so the app can serve it same-origin.
 *
 * MapLibre v6 derives its worker URL from `import.meta.url`. Next's production
 * bundle rewrites that to a module path, MapLibre rejects any non-http(s) base
 * and returns an empty string, and `new Worker("")` fails silently — no tiles,
 * no glyphs, a blank basemap and no error. station-map.tsx therefore calls
 * setWorkerUrl('/maplibre-gl-worker.mjs'), which only works if that file is
 * actually there.
 *
 * The copy must match the installed package exactly. A worker from a different
 * MapLibre version than the main bundle is the kind of mismatch that produces
 * incoherent runtime failures, so this runs on install and before every build
 * rather than being maintained by hand. Never edit the copied file.
 */

import { copyFileSync, existsSync, mkdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'node_modules', 'maplibre-gl', 'dist', 'maplibre-gl-worker.mjs');
const destination = join(root, 'public', 'maplibre-gl-worker.mjs');

if (!existsSync(source)) {
  // Loud, not silent: a missing worker only shows up as a blank map at
  // runtime, which is exactly the failure this script exists to prevent.
  console.error(
    `sync-maplibre-worker: ${source} not found.\n` +
      'Install dependencies first, or check whether the MapLibre package layout changed.',
  );
  process.exit(1);
}

mkdirSync(dirname(destination), { recursive: true });
copyFileSync(source, destination);

console.log(`sync-maplibre-worker: copied ${statSync(destination).size} bytes to public/maplibre-gl-worker.mjs`);
