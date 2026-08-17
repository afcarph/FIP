import { act, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { DriverDevice, FleetDriver } from '@/types/api';

/**
 * Getting one driver onto the app.
 *
 * The page exists because the checklist asked a fleet manager to do something
 * the product gave them no way to do. What these protect is that it keeps
 * telling the truth about where a driver has actually got to — and that it
 * never renders a store button that goes nowhere.
 *
 * The sharpest case is a handset with no vehicle attached. That is a normal,
 * common state — registration never attaches one — and the page used to call
 * it "not installed", because it was asking fleet device health, which only
 * lists devices that already have a vehicle.
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

function handset(overrides: Partial<DriverDevice> = {}): DriverDevice {
  return {
    id: 11,
    device_name: 'Pilot Pixel',
    platform: 'android',
    app_version: '1.0.0',
    last_seen_at: null,
    registered_at: null,
    vehicle: null,
    ...overrides,
  };
}

const withAccount = { account: { id: 9, email: 'pilot@haulers.test' } };

describe('DriverSetup', () => {
  beforeEach(() => {
    createAccount.mockClear();
    toDataURL.mockClear();
    toDataURL.mockImplementation(() => new Promise<string>(() => {}));
  });

  // ------------------------------------------- the exact production bug ---

  it('calls a handset with no vehicle installed, not missing', () => {
    /*
     * The regression. Account created, app installed, signed in, `vehicle`
     * null because nobody has assigned them a truck yet. This is the state
     * that read "Nothing has reported yet" while the checklist said done.
     */
    render(<DriverSetup driver={driver(withAccount)} device={handset()} />);

    expect(screen.getByText(/is signed in for Pilot Driver/)).toBeTruthy();
    expect(screen.queryByText(/Nothing has reported yet/)).toBeNull();
  });

  it('says why an installed phone is not reporting yet, without blaming the install', () => {
    render(<DriverSetup driver={driver(withAccount)} device={handset()} />);

    expect(screen.getByText(/not attached to a vehicle yet/)).toBeTruthy();
    expect(screen.getByText(/assigned a vehicle in step 3/)).toBeTruthy();
  });

  it('ticks install but not reporting when there is no vehicle', () => {
    // Two steps, two different answers: the app is on the phone, and it is not
    // yet reporting. Conflating them is the bug.
    const { container } = render(
      <DriverSetup driver={driver(withAccount)} device={handset()} />,
    );

    // Login and install are ticked; assignment and reporting are not.
    expect(container.querySelectorAll('.bg-emerald-600')).toHaveLength(2);
  });

  it('reports fully once the device carries a vehicle', () => {
    render(
      <DriverSetup
        driver={driver({ ...withAccount, assigned_vehicle: { id: 1, plate_number: 'NOV1616' } })}
        device={handset({ vehicle: { id: 1, plate_number: 'NOV1616' } })}
      />,
    );

    expect(screen.getByText(/Reporting for/)).toBeTruthy();
    expect(screen.queryByText(/not attached to a vehicle yet/)).toBeNull();
  });

  it('treats no active handset as not installed', () => {
    // Revoked and browser registrations never reach this component: the
    // endpoint filters them out, so they arrive as null.
    render(<DriverSetup driver={driver(withAccount)} device={null} />);

    expect(screen.getByText(/Nothing has reported yet/)).toBeTruthy();
    expect(screen.getByText(/not on the stores yet/i)).toBeTruthy();
  });

  // ------------------------------------------------------ the other steps ---

  it('draws a scannable code pointing at sign-in', async () => {
    toDataURL.mockResolvedValueOnce('data:image/png;base64,stub');

    await act(async () => {
      render(<DriverSetup driver={driver()} device={null} />);
    });

    // Rendered locally rather than fetched from a QR service: the value is a
    // company's sign-in address, and there is no reason to hand it to anyone.
    expect(toDataURL).toHaveBeenCalledWith(expect.stringContaining('/login'));
    expect(screen.getByAltText(/QR code linking to/)).toBeTruthy();
  });

  it('offers to create a login when the driver has none', () => {
    // The gap the whole milestone exists for: a driver record with no account
    // cannot open the app, and nothing used to say so.
    render(<DriverSetup driver={driver()} device={null} />);

    expect(screen.getByText(/has no login yet/)).toBeTruthy();
    expect(screen.getByRole('button', { name: /create login/i })).toBeTruthy();
  });

  it('shows the sign-in address once the driver has a login', () => {
    render(<DriverSetup driver={driver(withAccount)} device={null} />);

    expect(screen.getByText('pilot@haulers.test')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /create login/i })).toBeNull();
  });

  it('says the app is not on the stores rather than showing dead buttons', () => {
    // No listing exists yet. A store button that 404s would be worst on the
    // one screen whose job is telling somebody how to install something.
    render(<DriverSetup driver={driver()} device={null} />);

    expect(screen.getByText(/not on the stores yet/i)).toBeTruthy();
    expect(screen.queryByRole('link', { name: /google play/i })).toBeNull();
    expect(screen.queryByRole('link', { name: /app store/i })).toBeNull();
  });

  it('explains that an unassigned driver reports nothing', () => {
    // The dependency the app itself enforces: it refuses to guess a vehicle.
    render(<DriverSetup driver={driver()} device={null} />);

    expect(screen.getByText(/refuses to guess which vehicle/)).toBeTruthy();
  });

  it('names the vehicle once the driver is assigned to one', () => {
    render(
      <DriverSetup
        driver={driver({ assigned_vehicle: { id: 1, plate_number: 'NOV1616' } })}
        device={null}
      />,
    );

    expect(screen.getByText('NOV1616')).toBeTruthy();
  });

  it('keeps the phone and the assignment as separate answers', () => {
    // A driver with the app installed is not thereby assigned, and an assigned
    // driver has not thereby installed anything.
    render(<DriverSetup driver={driver(withAccount)} device={handset()} />);

    expect(screen.getByText(/refuses to guess which vehicle/)).toBeTruthy();
    expect(screen.getByText(/is signed in for Pilot Driver/)).toBeTruthy();
  });
});
