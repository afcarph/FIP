import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { relativeImports, sync } from '../../scripts/sync-maplibre-worker.mjs';

/**
 * The worker mirror.
 *
 * MapLibre's worker is not self-contained — it imports a sibling module — and
 * serving it alone produced a 404, a worker that never evaluated, and a map
 * that rendered nothing while every mechanical signal stayed green. These
 * tests assert the mirror is complete and byte-exact, because the failure it
 * prevents is invisible to HTTP status codes and container builds alike.
 */

const dist = join(process.cwd(), 'node_modules', 'maplibre-gl', 'dist');
const publicDir = join(process.cwd(), 'public');
const WORKER = 'maplibre-gl-worker.mjs';

const digest = (path: string) => createHash('sha256').update(readFileSync(path)).digest('hex');

describe('sync-maplibre-worker', () => {
  const copied = sync({ quiet: true });

  it('finds the worker in the installed package', () => {
    expect(existsSync(join(dist, WORKER))).toBe(true);
  });

  it('copies the worker into public/', () => {
    expect(existsSync(join(publicDir, WORKER))).toBe(true);
  });

  it('detects the worker static dependency rather than assuming none', () => {
    const imports = relativeImports(readFileSync(join(dist, WORKER), 'utf8'));

    expect(imports.length).toBeGreaterThan(0);
    expect(imports).toContain('maplibre-gl-shared.mjs');
  });

  it('copies maplibre-gl-shared.mjs', () => {
    expect(existsSync(join(publicDir, 'maplibre-gl-shared.mjs'))).toBe(true);
  });

  it('places every dependency beside the worker, where a relative import resolves', () => {
    // The worker is served from the public root, so `./x.mjs` resolves to
    // `/x.mjs`. A dependency anywhere else is a 404 and a dead worker.
    for (const name of copied) {
      expect(existsSync(join(publicDir, name))).toBe(true);
    }
  });

  it('keeps the worker byte-identical to the installed package', () => {
    expect(digest(join(publicDir, WORKER))).toBe(digest(join(dist, WORKER)));
  });

  it('keeps the shared dependency byte-identical to the installed package', () => {
    const name = 'maplibre-gl-shared.mjs';

    expect(digest(join(publicDir, name))).toBe(digest(join(dist, name)));
  });

  it('mirrors the worker and its dependency from the same installed version', () => {
    // Different versions either side of the worker boundary fail at runtime in
    // ways far harder to read than a missing file.
    expect(copied).toEqual([WORKER, 'maplibre-gl-shared.mjs']);
  });

  it('refuses a non-sibling import instead of copying it somewhere wrong', () => {
    expect(() => relativeImports('import { a } from "../elsewhere/thing.mjs";')).toThrow();
  });

  it('ignores bare specifiers, which are not files to mirror', () => {
    expect(relativeImports('import x from "some-package";')).toEqual([]);
  });
});
