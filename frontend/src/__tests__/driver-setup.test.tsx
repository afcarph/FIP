import { act, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { FleetDriver } from '@/types/api';

/**
 * Getting one driver onto the app.
 *
 * The page exists because the checklist asked a fleet manager to do something
 * the product gave them no way to do. What these protect is that it keeps
 * telling the truth about where a driver has actually got to — and that it
 * never renders a store button that goes nowhere.
 */

const createAccount = vi.fn();

vi.mock('@/hooks/use-api', () => ({
  useCreateDriverAccount: () => ({
    mutate: createAccount,
    isPending: false,
    error: null,
    data: undefined,
  }),
}));

/*
 * Pending by default. The QR is drawn in an effect, and a promise that
 * resolves during render updates state outside `act` — which is a warning
 * about a real hazard, not noise, so it is avoided rather than silenced. The
 * one test that cares about the image resolves it deliberately.
 */
const toDataURL = vi.fn<(value: string) => Promise<string>>(() => new Promise<string>(() => {}));

vi.mock('qrcode', () => ({ default: { toDataURL: (value: string) => toDataURL(value) } }));

const { DriverSetup } = await import('@/components/fleet/driver-setup');

function driver(overrides: Partial<FleetDriver> = {}): FleetDriver {
  return {
    id: 3,
    company_id: 1,
    employee_no: null,
    full_name: 'Pilot Driver',
    phone: null,
    status: 'active',
    licence: { number: null, type: null, expiry: null, expires_in_days: null },
    scores: { safety: null, efficiency: null },
    hired_at: null,
    account: null,
    ...overrides,
  } as FleetDriver;
}

describe('DriverSetup', () => {
  beforeEach(() => {
    createAccount.mockClear();
    toDataURL.mockClear();
    toDataURL.mockImplementation(() => new Promise<string>(() => {}));
  });

  it('draws a scannable code pointing at sign-in', async () => {
    toDataURL.mockResolvedValueOnce('data:image/png;base64,stub');

    await act(async () => {
      render(<DriverSetup driver={driver()} hasDevice={false} />);
    });

    // Rendered locally rather than fetched from a QR service: the value is a
    // company's sign-in address, and there is no reason to hand it to anyone.
    expect(toDataURL).toHaveBeenCalledWith(expect.stringContaining('/login'));
    expect(screen.getByAltText(/QR code linking to/)).toBeTruthy();
  });

  it('offers to create a login when the driver has none', () => {
    // The gap the whole milestone exists for: a driver record with no account
    // cannot open the app, and nothing used to say so.
    render(<DriverSetup driver={driver()} hasDevice={false} />);

    expect(screen.getByText(/has no login yet/)).toBeTruthy();
    expect(screen.getByRole('button', { name: /create login/i })).toBeTruthy();
  });

  it('shows the sign-in address once the driver has a login', () => {
    render(
      <DriverSetup
        driver={driver({ account: { id: 9, email: 'pilot@haulers.test' } })}
        hasDevice={false}
      />,
    );

    expect(screen.getByText('pilot@haulers.test')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /create login/i })).toBeNull();
  });

  it('says the app is not on the stores rather than showing dead buttons', () => {
    // No listing exists yet. A store button that 404s would be worst on the
    // one screen whose job is telling somebody how to install something.
    render(<DriverSetup driver={driver()} hasDevice={false} />);

    expect(screen.getByText(/not on the stores yet/i)).toBeTruthy();
    expect(screen.queryByRole('link', { name: /google play/i })).toBeNull();
    expect(screen.queryByRole('link', { name: /app store/i })).toBeNull();
  });

  it('waits for a real handset before calling the phone step done', () => {
    const { container } = render(
      <DriverSetup
        driver={driver({ account: { id: 9, email: 'pilot@haulers.test' } })}
        hasDevice={false}
      />,
    );

    expect(screen.getByText(/Nothing has reported yet/)).toBeTruthy();
    // Only the login step is ticked; three circles remain.
    expect(container.querySelectorAll('.bg-emerald-600')).toHaveLength(1);
  });

  it('reports the phone as connected once one has registered', () => {
    render(
      <DriverSetup
        driver={driver({ account: { id: 9, email: 'pilot@haulers.test' } })}
        hasDevice
      />,
    );

    expect(screen.getByText(/is registered for Pilot Driver/)).toBeTruthy();
  });

  it('explains that an unassigned driver reports nothing', () => {
    // The dependency the app itself enforces: it refuses to guess a vehicle.
    render(<DriverSetup driver={driver()} hasDevice={false} />);

    expect(screen.getByText(/refuses to guess which vehicle/)).toBeTruthy();
  });

  it('names the vehicle once the driver is assigned to one', () => {
    render(
      <DriverSetup
        driver={driver({ assigned_vehicle: { id: 1, plate_number: 'NOV1616' } })}
        hasDevice={false}
      />,
    );

    expect(screen.getByText('NOV1616')).toBeTruthy();
  });

  it('keeps the phone and the assignment as separate answers', () => {
    // A driver with the app installed is not thereby assigned, and an assigned
    // driver has not thereby installed anything.
    render(
      <DriverSetup
        driver={driver({ account: { id: 9, email: 'p@h.test' } })}
        hasDevice
      />,
    );

    expect(screen.getByText(/refuses to guess which vehicle/)).toBeTruthy();
    expect(screen.getByText(/is registered for Pilot Driver/)).toBeTruthy();
  });
});
