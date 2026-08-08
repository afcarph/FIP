# Maps

FIP is free for public users, so the interactive map must not sit on a
per-load commercial quota. The renderer is **MapLibre GL JS**, the tiles come
from an **OSM-derived provider**, and Google is used only for two things that
cost nothing: opening a location and starting navigation.

```
interactive map   →  MapLibre GL JS  →  OSM-derived tiles (MapTiler)
station data      →  gas_stations    →  markers, coordinates
DOE data          →  fuel_reports / fuel_prices  →  price references
navigation        →  google.com/maps URLs        →  Google Maps app or browser
```

## Why this shape

**MapLibre, not Google Maps JS.** The Maps JavaScript API bills per map load.
For an application intended to be free and public, that is a cost that scales
with success. MapLibre is BSD-licensed and renders any style JSON, so the
renderer and the tile source are independent.

**Google URLs for navigation.** `google.com/maps/...` links are not the Maps
API — no key, no quota, nothing billable — and they hand the user off to the
app they already have turn-by-turn directions in. Building navigation
ourselves would be worse and more expensive.

## Tile provider

**MapTiler**, chosen against these requirements:

| Requirement | MapTiler |
|---|---|
| Philippines coverage | Full OSM coverage |
| MapLibre support | Native; publishes MapLibre style JSON |
| Web and mobile | Same style URL serves both |
| Free tier | 100,000 tile loads/month |
| Commercial/public use | Permitted on the free tier with attribution |
| Attribution | Supplied in the style; MapLibre renders it |
| Migration | Style URL only — see below |

**`tile.openstreetmap.org` is not used and must not be.** It is a
volunteer-funded service whose usage policy forbids exactly this kind of
product traffic. Pointing a public application at it is both a breach of that
policy and an outage waiting to happen.

### Style URL format

```
https://api.maptiler.com/maps/{style-id}/style.json?key={KEY}
```

Style ids include `streets-v2`, `basic-v2`, `bright-v2`, `dataviz`,
`openstreetmap`, `outdoor-v2`, `topo-v2`, `satellite`.

The key is a **client** key. It ships in the browser bundle by necessity, as
any browser map key must, and is protected by a **domain allowlist in the
MapTiler dashboard** rather than by secrecy. It is still read from the
environment so it is never committed.

### Configuration

| Variable | Where | Value |
|---|---|---|
| `NEXT_PUBLIC_MAP_STYLE_URL` | web build + runtime | the style URL above |

Unset, the map falls back to MapLibre's keyless demo tiles and says so on
screen. That is for local development and CI only — it is a low-detail world
basemap, not a product.

Everything provider-specific lives in `frontend/src/lib/map-config.ts`. No
other file names a provider.

### Changing provider

1. Obtain a style URL from the new provider.
2. Change `NEXT_PUBLIC_MAP_STYLE_URL`.
3. Rebuild the web image.

No component changes. Any MapLibre-compatible style works — Stadia, Protomaps,
a self-hosted TileServer GL, or a PMTiles file on object storage. **Protomaps**
is the natural next step if tile volume outgrows the free tier: a single
self-hosted basemap file removes per-tile cost entirely.

### Attribution

OSM's licence requires attribution, and the provider's terms require theirs.
Both are declared in the style JSON and rendered by MapLibre's attribution
control. **Do not remove or hide that control** — it is a licence condition,
not decoration.

## Station data

Markers come from `gas_stations.latitude/longitude` and nowhere else. Nothing
is geocoded, nothing is inferred, and no station coordinate comes from Google.

`hasPlottableCoordinates` refuses to plot: nulls, non-finite values, `0,0`
(what a failed import leaves behind, and it is in the Atlantic), and anything
outside the Philippine bounding box. A marker in the wrong place is worse than
no marker — it asserts something false about where a forecourt is.

**Audited 8 Aug 2026: 26 stations, 0 null, 0 at `0,0`, 0 outside bounds.**

## User location

Requested **only** when the user presses "Use my location". The map page passes
`immediate: false` to `useGeolocation`; nothing asks on mount. A page that
demands location before the user has asked for anything trains them to refuse
the prompt, and a refusal is per-origin and sticky.

Denied, unavailable and timeout are all handled — see
`frontend/src/hooks/use-geolocation.ts`. Where the browser has blocked the site
outright, the UI says so and explains the fix, because script cannot re-prompt.

## Navigation links

```ts
googleMapsUrl.showLocation(lat, lng)
// https://www.google.com/maps/search/?api=1&query=LAT,LNG

googleMapsUrl.directions(lat, lng, origin?)
// https://www.google.com/maps/dir/?api=1&destination=LAT,LNG[&origin=LAT,LNG]
```

Both work with the map unavailable, with no key configured, and with location
refused. When the user's position is unknown the origin is omitted, letting
Google use the device's own fix rather than a stale one of ours.

## When the map fails

A failed style load, a rejected key or a browser without WebGL shows **"Map
temporarily unavailable"** in the map panel. The station list, search, Show
Location and Get Directions all keep working. A directory that dies with its
basemap is worse than one with no map at all.

## Cost

| Surface | Billable |
|---|---|
| Map tiles | MapTiler free tier, 100k loads/month |
| Show Location / Get Directions | Nothing — plain URLs |
| Nearby distance | Nothing — `ST_Distance_Sphere` in MySQL, Haversine on SQLite |
| Station search | Nothing — the platform's own database |

**No Places, Routes, Geocoding or Directions API is used anywhere.** No
third-party call is made per station.
