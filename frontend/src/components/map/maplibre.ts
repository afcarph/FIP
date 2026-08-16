/**
 * The only place MapLibre is imported from.
 *
 * MapLibre v6 resolves its worker URL from `import.meta.url`, and refuses any
 * base that is not http(s) — returning an empty string rather than throwing.
 * Next's production bundle replaces `import.meta.url` with a module path, so
 * the default resolution yields `new Worker("")`: the worker dies, and with it
 * every vector tile and glyph, while the style, sprites and DOM markers load
 * normally. The result is a blank basemap with no error in sight.
 *
 * Pointing MapLibre at a same-origin copy of its own worker is the supported
 * fix (setWorkerUrl is public API in 6.2.0). The copy is kept in step with the
 * installed package by scripts/sync-maplibre-worker.mjs — never edit it, and
 * never let it drift from the version in node_modules.
 *
 * The setting is global to the library, so it only has to run once — but it
 * has to run *before the first map is constructed*, which is why it lives with
 * the import rather than in any one component. Import MapLibre from here, not
 * from 'maplibre-gl', and a new map cannot be built without it.
 */

import { setWorkerUrl } from 'maplibre-gl';

export * from 'maplibre-gl';

if (typeof window !== 'undefined') {
  setWorkerUrl('/maplibre-gl-worker.mjs');
}
