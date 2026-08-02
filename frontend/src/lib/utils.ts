import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/** Peso formatting. Fuel prices carry 2 decimals; totals round to whole pesos. */
export function formatCurrency(value: number | null | undefined, decimals = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';

  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(value);
}

/** Compact form for dashboard tiles: ₱1.2M rather than ₱1,234,567.00. */
export function formatCompactCurrency(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';

  if (Math.abs(value) < 10_000) return formatCurrency(value, 0);

  return `₱${new Intl.NumberFormat('en-PH', {
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(value)}`;
}

export function formatNumber(value: number | null | undefined, decimals = 0): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';

  return new Intl.NumberFormat('en-PH', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(value);
}

export function formatLitres(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${formatNumber(value, 1)} L`;
}

export function formatDistance(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${formatNumber(value, 1)} km`;
}

export function formatEfficiency(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${formatNumber(value, 1)} km/L`;
}

/** Signed percentage with an explicit sign, for movement indicators. */
export function formatPercent(value: number | null | undefined, decimals = 1): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';

  return `${value > 0 ? '+' : ''}${value.toFixed(decimals)}%`;
}

export function formatDate(value: string | Date | null | undefined): string {
  if (!value) return '—';

  return new Intl.DateTimeFormat('en-PH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(value));
}

export function formatDateTime(value: string | Date | null | undefined): string {
  if (!value) return '—';

  return new Intl.DateTimeFormat('en-PH', {
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value));
}

/** "3 days ago" / "in 2 weeks" — relative phrasing for recency-heavy UI. */
export function formatRelative(value: string | Date | null | undefined): string {
  if (!value) return '—';

  const date = new Date(value);
  const diffSeconds = (date.getTime() - Date.now()) / 1000;
  const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

  const thresholds: [number, Intl.RelativeTimeFormatUnit][] = [
    [60, 'second'],
    [3600, 'minute'],
    [86400, 'hour'],
    [604800, 'day'],
    [2592000, 'week'],
    [31536000, 'month'],
  ];

  let previous = 1;

  for (const [limit, unit] of thresholds) {
    if (Math.abs(diffSeconds) < limit) {
      return formatter.format(Math.round(diffSeconds / previous), unit);
    }
    previous = limit;
  }

  return formatter.format(Math.round(diffSeconds / 31536000), 'year');
}

/** Movement direction — drives both colour and icon so meaning is redundant. */
export function priceTrend(change: number | null | undefined): 'up' | 'down' | 'flat' {
  if (change === null || change === undefined) return 'flat';
  if (change > 0.001) return 'up';
  if (change < -0.001) return 'down';
  return 'flat';
}

export function trendClasses(trend: 'up' | 'down' | 'flat'): string {
  return {
    up: 'text-price-up',
    down: 'text-price-down',
    flat: 'text-price-flat',
  }[trend];
}

/** Haversine distance in kilometres, for client-side proximity display. */
export function distanceKm(
  lat1: number,
  lng1: number,
  lat2: number,
  lng2: number,
): number {
  const toRad = (deg: number) => (deg * Math.PI) / 180;
  const R = 6371;

  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);

  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;

  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

export function initials(name: string | null | undefined): string {
  if (!name) return '?';

  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');
}
