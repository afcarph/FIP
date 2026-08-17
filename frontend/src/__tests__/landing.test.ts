import { describe, expect, it } from 'vitest';

import { landingFor } from '@/hooks/use-auth';

/**
 * Where an account belongs after signing in.
 *
 * This is now load-bearing twice over: it chooses the page login sends people
 * to, and the sidebar hides the personal Dashboard entry from anyone it does
 * not send there. So a change here silently changes the navigation too, which
 * is the reason it is pinned.
 *
 * The case that matters most is an account holding several roles. Every fleet
 * manager on this system also holds `user`, so any rule written as "show the
 * personal dashboard to users" matches them as well — which is how the entry
 * came to sit above Fleet overview, leading to a page built from that one
 * person's own fill-ups and own vehicles.
 */
describe('where a signed-in account lands', () => {
  it('sends a platform administrator to the console', () => {
    expect(landingFor(['super_admin'])).toBe('/admin');
    expect(landingFor(['system_admin'])).toBe('/admin');
  });

  it('sends fleet roles to the fleet', () => {
    expect(landingFor(['fleet_manager'])).toBe('/fleet');
    expect(landingFor(['company_manager'])).toBe('/fleet');
    expect(landingFor(['viewer'])).toBe('/fleet');
  });

  it('sends a private motorist to their own dashboard', () => {
    expect(landingFor(['user'])).toBe('/dashboard');
  });

  it('sends a driver to their own dashboard', () => {
    // Drivers are not a fleet role here: they log their own fill-ups and read
    // their own vehicle, so the personal page is genuinely theirs.
    expect(landingFor(['driver'])).toBe('/dashboard');
  });

  it('treats a fleet manager who also holds user as a fleet account', () => {
    // The real shape of every fleet account on this system, and the reason the
    // sidebar rule could not be written as a list of personal roles.
    expect(landingFor(['fleet_manager', 'user'])).toBe('/fleet');
    expect(landingFor(['user', 'fleet_manager'])).toBe('/fleet');
  });

  it('lets the platform console win over a fleet role', () => {
    expect(landingFor(['super_admin', 'fleet_manager', 'user'])).toBe('/admin');
  });

  it('falls back to the personal dashboard for an unknown role', () => {
    // A role nobody has taught this about must still land somewhere it can
    // read, and the personal page needs no company.
    expect(landingFor(['guest'])).toBe('/dashboard');
    expect(landingFor([])).toBe('/dashboard');
  });
});
