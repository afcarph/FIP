/**
 * Where a driver gets the app.
 *
 * FIP is not on either store yet. The Android release build is still signed
 * with the debug key, and no Play or App Store listing exists — so a page
 * rendering store buttons today would be a pair of dead links on the one
 * screen whose whole job is telling somebody how to install something.
 *
 * The destinations are therefore configuration, empty by default. When the
 * listings go live these become two environment variables and the UI changes
 * on its own; until then `pending` is true and the page says plainly that the
 * app is not published yet, rather than pretending.
 */

export interface AppLinks {
  android: string | null;
  ios: string | null;
  /** Neither store is configured, so there is nothing honest to link to. */
  pending: boolean;
}

function configured(value: string | undefined): string | null {
  const trimmed = value?.trim();

  return trimmed ? trimmed : null;
}

export function appLinks(): AppLinks {
  const android = configured(process.env.NEXT_PUBLIC_ANDROID_APP_URL);
  const ios = configured(process.env.NEXT_PUBLIC_IOS_APP_URL);

  return { android, ios, pending: android === null && ios === null };
}

/**
 * What a driver types in, or what a QR code carries.
 *
 * The sign-in page rather than a store, deliberately: it is the one address
 * that is true on both platforms and stays true after the stores are live. A
 * driver who scans this on a phone with the app already installed lands
 * somewhere useful either way.
 */
export function driverSignInUrl(): string {
  if (typeof window === 'undefined') return '/login';

  return `${window.location.origin}/login`;
}
