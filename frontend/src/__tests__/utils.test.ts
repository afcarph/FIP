import { describe, expect, it } from 'vitest';

import {
  distanceKm,
  formatCompactCurrency,
  formatCurrency,
  formatEfficiency,
  formatPercent,
  initials,
  priceTrend,
} from '@/lib/utils';

describe('currency formatting', () => {
  it('formats pesos with two decimals', () => {
    expect(formatCurrency(58.5)).toBe('₱58.50');
    expect(formatCurrency(1234.567)).toBe('₱1,234.57');
  });

  it('returns an em dash for missing values rather than "NaN"', () => {
    expect(formatCurrency(null)).toBe('—');
    expect(formatCurrency(undefined)).toBe('—');
    expect(formatCurrency(Number.NaN)).toBe('—');
  });

  it('compacts only above the readability threshold', () => {
    // Below ₱10k the exact figure still fits, so it is not abbreviated.
    expect(formatCompactCurrency(2500)).toBe('₱2,500');
    expect(formatCompactCurrency(1_250_000)).toContain('1.3M');
  });
});

describe('price trend', () => {
  it('classifies movement with a dead band around zero', () => {
    expect(priceTrend(0.5)).toBe('up');
    expect(priceTrend(-0.5)).toBe('down');
    expect(priceTrend(0)).toBe('flat');
    // Sub-centavo noise should not read as a movement.
    expect(priceTrend(0.0001)).toBe('flat');
    expect(priceTrend(null)).toBe('flat');
  });
});

describe('percentages', () => {
  it('always carries an explicit sign', () => {
    expect(formatPercent(12.34)).toBe('+12.3%');
    expect(formatPercent(-5)).toBe('-5.0%');
    expect(formatPercent(null)).toBe('—');
  });
});

describe('efficiency', () => {
  it('appends the unit', () => {
    expect(formatEfficiency(12.345)).toBe('12.3 km/L');
    expect(formatEfficiency(null)).toBe('—');
  });
});

describe('distance', () => {
  it('measures Makati to Quezon City at roughly 14 km', () => {
    const km = distanceKm(14.5547, 121.0244, 14.676, 121.0437);

    expect(km).toBeGreaterThan(13);
    expect(km).toBeLessThan(15);
  });

  it('returns zero for identical points', () => {
    expect(distanceKm(14.5, 121.0, 14.5, 121.0)).toBe(0);
  });
});

describe('initials', () => {
  it('takes the first letter of the first two words', () => {
    expect(initials('Ella Santos')).toBe('ES');
    expect(initials('Jomar Dela Cruz')).toBe('JD');
    expect(initials('Cher')).toBe('C');
    expect(initials(null)).toBe('?');
  });
});
