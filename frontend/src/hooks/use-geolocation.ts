'use client';

import * as React from 'react';

interface GeolocationState {
  latitude: number | null;
  longitude: number | null;
  accuracy: number | null;
  error: string | null;
  loading: boolean;
  permission: PermissionState | 'unsupported' | 'unknown';
}

const DEFAULT_LAT = Number(process.env.NEXT_PUBLIC_DEFAULT_LAT ?? 14.5547);
const DEFAULT_LNG = Number(process.env.NEXT_PUBLIC_DEFAULT_LNG ?? 121.0244);

/**
 * Browser geolocation with a graceful default.
 *
 * A user who declines location still needs a usable map and a "nearby"
 * list, so the hook falls back to Makati CBD and reports `usingFallback`
 * — the UI shows a "showing prices near Makati" note rather than an empty
 * screen or a permission nag.
 */
export function useGeolocation(options: { immediate?: boolean } = {}) {
  const { immediate = true } = options;

  const [state, setState] = React.useState<GeolocationState>({
    latitude: null,
    longitude: null,
    accuracy: null,
    error: null,
    loading: immediate,
    permission: 'unknown',
  });

  const request = React.useCallback(() => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      setState((previous) => ({
        ...previous,
        loading: false,
        error: 'Location is not supported by this browser.',
        permission: 'unsupported',
      }));
      return;
    }

    setState((previous) => ({ ...previous, loading: true, error: null }));

    navigator.geolocation.getCurrentPosition(
      (position) =>
        setState({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
          error: null,
          loading: false,
          permission: 'granted',
        }),
      (error) =>
        setState((previous) => ({
          ...previous,
          loading: false,
          error:
            error.code === error.PERMISSION_DENIED
              ? 'Location access was declined.'
              : 'Your location could not be determined.',
          permission: error.code === error.PERMISSION_DENIED ? 'denied' : previous.permission,
        })),
      // A slightly stale fix is fine here and much faster than forcing a new one.
      { enableHighAccuracy: true, timeout: 10_000, maximumAge: 300_000 },
    );
  }, []);

  // Ask the browser what it already decided, so the UI can tell "we have not
  // asked yet" apart from "the user said no and we cannot ask again". Without
  // this the two look identical: getCurrentPosition rejects a denied
  // permission in about two milliseconds, which reads as a button that does
  // nothing.
  React.useEffect(() => {
    let cancelled = false;

    if (typeof navigator === 'undefined' || !navigator.permissions?.query) return;

    navigator.permissions
      .query({ name: 'geolocation' as PermissionName })
      .then((status) => {
        if (cancelled) return;

        setState((previous) => ({ ...previous, permission: status.state }));

        // Re-enabling it in site settings should not need a page reload.
        status.onchange = () => {
          setState((previous) => ({ ...previous, permission: status.state }));

          if (status.state === 'granted') request();
        };
      })
      // Firefox and older Safari do not expose the geolocation permission
      // here. Leaving the state as `unknown` is correct — it means we do not
      // know, not that it is denied.
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [request]);

  React.useEffect(() => {
    if (immediate) request();
  }, [immediate, request]);

  const usingFallback = state.latitude === null;

  return {
    /**
     * The browser has refused and will not prompt again. Nothing the page can
     * do will change this — only the user, in site settings — so the UI has
     * to say so rather than offering a button that fails silently.
     */
    isBlocked: state.permission === 'denied',
    ...state,
    latitude: state.latitude ?? DEFAULT_LAT,
    longitude: state.longitude ?? DEFAULT_LNG,
    usingFallback,
    request,
  };
}
