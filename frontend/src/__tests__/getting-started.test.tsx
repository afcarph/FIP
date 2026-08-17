import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { OnboardingProgress } from '@/types/api';

/**
 * The getting-started checklist.
 *
 * Three properties are worth protecting, and none of them are about layout.
 *
 * It must disappear on its own when the work is done, because that is what
 * makes it a guide rather than furniture. It must ask for exactly one thing,
 * because the order is what the product requires and a step that cannot be
 * done yet is noise. And hiding it must be one person's choice on one machine
 * — a shared depot computer is normal, and a colleague signing in afterwards
 * has a fleet that still needs setting up.
 */

let progress: OnboardingProgress;
let currentUser: { id: number } | null;

vi.mock('@/hooks/use-api', () => ({
  useOnboarding: () => ({ data: progress }),
}));

vi.mock('@/hooks/use-auth', () => ({
  useAuth: () => ({ user: currentUser }),
}));

const { GettingStarted } = await import('@/components/fleet/getting-started');

function steps(done: string[]): OnboardingProgress['steps'] {
  const catalogue: Array<[string, string, string, string]> = [
    ['vehicle', 'Add your first vehicle', '/vehicles/new', 'Add a vehicle'],
    ['driver', 'Add a driver', '/fleet/drivers', 'Add a driver'],
    ['assignment', 'Put a driver in a vehicle', '/fleet/assignments', 'Assign a driver'],
    ['device', 'Get the app on a driver’s phone', '/fleet/devices', 'See device health'],
    ['fuel', 'Log a fill-up', '/expenses/new', 'Log a fill-up'],
  ];

  return catalogue.map(([key, title, href, action]) => ({
    key,
    title,
    description: `Why ${key} matters`,
    href,
    action,
    done: done.includes(key),
  }));
}

function progressWith(done: string[]): OnboardingProgress {
  return {
    applies: true,
    steps: steps(done),
    completed: done.length,
    total: 5,
    is_complete: done.length === 5,
  };
}

describe('GettingStarted', () => {
  beforeEach(() => {
    window.localStorage.clear();
    currentUser = { id: 7 };
    progress = progressWith([]);
  });

  it('asks for the first thing and nothing else', () => {
    render(<GettingStarted />);

    // Every step is listed…
    expect(screen.getByText('Add your first vehicle')).toBeTruthy();
    expect(screen.getByText('Log a fill-up')).toBeTruthy();

    // …but only the next one explains itself and offers a way to do it.
    expect(screen.getByText('Why vehicle matters')).toBeTruthy();
    expect(screen.queryByText('Why driver matters')).toBeNull();
    expect(screen.getAllByRole('link')).toHaveLength(1);
  });

  it('moves the ask to the next undone step', () => {
    progress = progressWith(['vehicle', 'driver']);

    render(<GettingStarted />);

    expect(screen.getByText('Why assignment matters')).toBeTruthy();
    expect(screen.queryByText('Why vehicle matters')).toBeNull();
    expect(screen.getByRole('link').getAttribute('href')).toBe('/fleet/assignments');
  });

  it('says how far along the company is', () => {
    progress = progressWith(['vehicle', 'driver']);

    render(<GettingStarted />);

    expect(screen.getByText(/2 of 5 done/)).toBeTruthy();
  });

  it('disappears once everything is done', () => {
    // The property that makes this a guide and not furniture.
    progress = progressWith(['vehicle', 'driver', 'assignment', 'device', 'fuel']);

    const { container } = render(<GettingStarted />);

    expect(container.textContent).toBe('');
  });

  it('renders nothing for an account with no company', () => {
    progress = { applies: false };

    const { container } = render(<GettingStarted />);

    expect(container.textContent).toBe('');
  });

  it('renders nothing while the progress is still loading', () => {
    progress = undefined as unknown as OnboardingProgress;

    const { container } = render(<GettingStarted />);

    expect(container.textContent).toBe('');
  });

  describe('hiding it', () => {
    it('stays hidden for the person who hid it', () => {
      const { container, unmount } = render(<GettingStarted />);

      fireEvent.click(screen.getByRole('button', { name: 'Hide' }));
      expect(container.textContent).toBe('');

      unmount();

      // Still gone on the next visit — the preference outlives the render.
      const again = render(<GettingStarted />);
      expect(again.container.textContent).toBe('');
    });

    it('does not hide it from a colleague on the same machine', () => {
      // The bug this replaced: one global key meant hiding the list also hid
      // it from the next person to sign in on a shared office computer, whose
      // own fleet is the one still needing set up.
      render(<GettingStarted />);
      fireEvent.click(screen.getByRole('button', { name: 'Hide' }));

      currentUser = { id: 8 };

      const colleague = render(<GettingStarted />);

      expect(colleague.container.textContent).toContain('Getting started');
    });

    it('keeps the preference in the browser rather than sending it anywhere', () => {
      render(<GettingStarted />);
      fireEvent.click(screen.getByRole('button', { name: 'Hide' }));

      // Stored against this user, and nowhere else. Nothing about the company
      // changed, which is the point: it is a display choice, not a fact.
      expect(window.localStorage.getItem('fip.onboarding_dismissed.7')).toBe('true');
      expect(window.localStorage.getItem('fip.onboarding_dismissed.8')).toBeNull();
    });
  });
});
