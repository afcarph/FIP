#!/usr/bin/env node
/**
 * Copy MapLibre's worker — and everything it imports — into public/.
 *
 * Two failures made this necessary, and both were invisible until a browser
 * rendered nothing. MapLibre v6 derives its worker URL from `import.meta.url`;
 * Next's production bundle rewrites that to a module path, MapLibre rejects any
 * non-http(s) base and returns an empty string, and `new Worker("")` fails
 * silently. station-map.tsx therefore pins the worker to a same-origin path.
 *
 * The second failure was assuming that worker was self-contained. It is not —
 * it statically imports ./maplibre-gl-shared.mjs, so serving the worker alone
 * gives a 404 on the sibling, the module graph dies before evaluation, and the
 * symptom is once again a blank map with an HTTP 200 next to it.
 *
 * So this resolves the worker's imports rather than trusting a hard-coded list:
 * if a future MapLibre changes its dependency graph, the build fails loudly
 * here instead of shipping a map that renders nothing.
 */

import { copyFileSync, existsSync, mkdirSync, readFileSync, readdirSync, statSync, unlinkSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'node_modules', 'maplibre-gl', 'dist');
const publicDir = join(root, 'public');

const WORKER = 'maplibre-gl-worker.mjs';

/** Static `from "./x.mjs"` and `import "./x.mjs"` specifiers, relative only. */
export function relativeImports(source) {
  const found = new Set();

  for (const pattern of [/\bfrom\s*["'](\.[^"']+)["']/g, /\bimport\s*["'](\.[^"']+)["']/g]) {
    for (const match of source.matchAll(pattern)) {
      // Only siblings can be honoured: the worker is served from the public
      // root, so anything above it has nowhere to resolve to.
      const specifier = match[1].replace(/^\.\//, '');

      if (specifier.includes('/')) {
        fail(`worker imports "${match[1]}", which is not a sibling module. This script only mirrors siblings; the layout has changed and needs review.`);
      }

      found.add(specifier);
    }
  }

  return [...found];
}

/** Throws so callers can test the failure paths; the CLI turns it into exit 1. */
function fail(message) {
  throw new Error(`sync-maplibre-worker: ${message}`);
}

export function sync({ quiet = false } = {}) {
  const workerSource = join(dist, WORKER);

  if (!existsSync(workerSource)) {
    fail(`${workerSource} not found. Install dependencies first, or check whether the MapLibre package layout changed.`);
  }

  const source = readFileSync(workerSource, 'utf8');
  const dependencies = relativeImports(source);
  const wanted = [WORKER, ...dependencies];

  mkdirSync(publicDir, { recursive: true });

  for (const name of wanted) {
    const from = join(dist, name);

    if (!existsSync(from)) {
      fail(`worker depends on "${name}" but ${from} does not exist. Serving the worker without it produces a 404 and a blank map.`);
    }

    copyFileSync(from, join(publicDir, name));

    if (!existsSync(join(publicDir, name))) fail(`failed to write public/${name}`);
  }

  // Drop dependencies from a previous MapLibre whose graph has since changed.
  // Scoped to the maplibre-gl-*.mjs namespace so nothing else in public/ is at
  // risk, and the current worker's own files are never candidates.
  for (const name of readdirSync(publicDir)) {
    if (/^maplibre-gl.*\.mjs$/.test(name) && !wanted.includes(name)) {
      unlinkSync(join(publicDir, name));
      if (!quiet) console.log(`sync-maplibre-worker: removed stale public/${name}`);
    }
  }

  if (!quiet) {
    for (const name of wanted) {
      console.log(`sync-maplibre-worker: ${name} (${statSync(join(publicDir, name)).size} bytes)`);
    }
  }

  return wanted;
}

// Only run when invoked directly, so the tests can import the helpers.
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  try {
    sync();
  } catch (error) {
    // Loud and non-zero: a silent miss here ships a map that renders nothing.
    console.error(error.message);
    process.exit(1);
  }
}
