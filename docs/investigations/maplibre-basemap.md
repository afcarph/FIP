# The blank basemap

**Status: closed, 2026-08-16. There was no bug in the map.** The symptom was an
artefact of how the page was being looked at: an automated browser tab that was
never brought to the front. `/map` on production renders MapTiler's basemap,
labels, roads, water and the clustered station markers, and always did.

## What was actually happening

A hidden tab does not run `requestAnimationFrame` callbacks. MapLibre schedules
its **first** render that way, and that first frame is what walks the style's
sources, computes the covering tiles and asks the worker to load them. Nothing
before the frame does any of that.

So in a tab that never became visible:

- the style, TileJSON and sprite were fetched and parsed — all 200;
- `Style._load` completed, `style._loaded` was `true`, both source caches existed;
- `_frameRequest` was set and stayed set for as long as the tab stayed hidden;
- `_sourcesDirty` and `_styleDirty` stayed `true` — queued work, never run;
- not one `loadTile` message was ever sent to the worker;
- the attribution control stayed empty, because attribution is populated from
  sources as they load;
- the map ignored `resize()`, because `_update()` returns early until a frame
  has run.

The moment the tab was fronted, the same instance loaded its style, streamed
tiles, filled in `© MapTiler © OpenStreetMap contributors` and rendered.

This also explains the observation that stalled the earlier investigation: a
freshly constructed map on the same page "worked completely" while the
application's own map did not. Those probes were run at moments when the page
was in the foreground. The two maps were never compared under the same
conditions.

## The two fixes that came out of it are still real

They were found on the way and are worth keeping, independently of the
misdiagnosis above.

**MapLibre v6's worker URL resolved to `""`.** v6 derives the worker URL from
`import.meta.url` and returns an empty string for any base that is not
http(s) — Next's production bundle rewrites that base to a module path. The
result was `new Worker("")`, a dead worker, and no tile parsing. Fixed in
`0c09fd8` via `setWorkerUrl`.

**The worker was not self-contained.** It statically imports
`./maplibre-gl-shared.mjs`, which was never copied, so the sibling 404'd and
the module graph died before evaluation. Fixed in `16fd2d4`; the sync script
now resolves the worker's imports rather than assuming there are none.

## What this cost, and the lesson that actually applies

The earlier write-up drew the right lesson from the wrong place. It said the
only trustworthy claims were "tied to a directly observed browser behaviour" —
but every observation here *was* a directly observed browser behaviour. They
were all observed through an instrument whose own state was part of the
experiment and was never written down.

The check that would have closed this in one step, and now belongs in any
browser-based diagnosis: **record `document.visibilityState` alongside the
measurement.** A page that is not visible is not idle-but-equivalent; whole
subsystems — rAF, timers, media, and everything built on them — are suspended.

The second lesson survives intact and was nearly repeated: an instrument that
misses known-present activity cannot prove absence. Two separate numbers here
looked like smoking guns and were not — a 481250-vs-481522 "mismatch" between
the deployed worker's shared module and the local one was `String.length`
counted against bytes on a file with 272 non-ASCII bytes, and three console
403s that seemed to be tile refusals were unrelated API calls. Both were
checked before being reported.

## Still open, unrelated

`docs/deployment.md` lists `Content-Security-Policy` as in place on staging.
It is not sent — `/map` returns only `X-Content-Type-Options`,
`X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` and HSTS.
Separately, the MapTiler key is passed as a `--build-arg`, which puts it in the
host process list during builds. It is a client key that ships in the bundle by
necessity and is restricted by domain at the provider — the restriction works;
requests for the same style from `localhost` are refused with 403.
