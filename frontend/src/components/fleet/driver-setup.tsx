'use client';

import { Check, Copy, KeyRound, Loader2, Smartphone, TriangleAlert } from 'lucide-react';
import QRCode from 'qrcode';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCreateDriverAccount } from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';
import { appLinks, driverSignInUrl } from '@/lib/app-distribution';
import type { FleetDriver } from '@/types/api';

/**
 * Getting one driver onto the app.
 *
 * The checklist asked a fleet manager to "get the app on a driver's phone" and
 * the product offered no way to do it — no install link, no instructions, and
 * no way to see whether the driver even had a login. This is that page.
 *
 * It follows the real order of the problem rather than the order of the
 * screens. A driver cannot sign in without an account, cannot report without
 * the app, and cannot be tracked without being assigned — so those are the
 * three things it shows, in that order, each saying whether it is done.
 */

function QrCode({ value }: { value: string }) {
  const [dataUrl, setDataUrl] = React.useState<string | null>(null);

  React.useEffect(() => {
    let cancelled = false;

    // Rendered in the browser rather than fetched from a QR service: the value
    // is a sign-in address for a specific company, and sending it to a third
    // party to draw would be handing away something for nothing.
    QRCode.toDataURL(value, { margin: 1, width: 176 })
      .then((url) => {
        if (!cancelled) setDataUrl(url);
      })
      .catch(() => {
        if (!cancelled) setDataUrl(null);
      });

    return () => {
      cancelled = true;
    };
  }, [value]);

  if (!dataUrl) {
    return <div className="size-44 animate-pulse rounded-lg bg-muted" aria-hidden />;
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={dataUrl}
      alt={`QR code linking to ${value}`}
      width={176}
      height={176}
      className="size-44 rounded-lg border border-border bg-white p-2"
    />
  );
}

function StepCard({
  index,
  title,
  done,
  children,
}: {
  index: number;
  title: string;
  done: boolean;
  children: React.ReactNode;
}) {
  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-3">
          <span
            className={
              done
                ? 'flex size-6 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white'
                : 'flex size-6 shrink-0 items-center justify-center rounded-full border border-border text-sm text-muted-foreground'
            }
            aria-hidden
          >
            {done ? <Check className="size-3.5" /> : index}
          </span>
          <CardTitle className="text-base">{title}</CardTitle>
        </div>
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

function AccountStep({ driver }: { driver: FleetDriver }) {
  const creation = useCreateDriverAccount(driver.id);
  const [email, setEmail] = React.useState('');
  const [copied, setCopied] = React.useState(false);

  const error = creation.error instanceof ApiError ? creation.error : null;
  const created = creation.data;

  if (created) {
    return (
      <div className="space-y-3">
        <p className="text-sm">
          <span className="font-medium">{created.user.email}</span> can now sign in.
        </p>

        <div className="rounded-lg border border-amber-500/40 bg-amber-500/[0.06] p-3">
          <p className="text-sm font-medium text-amber-800 dark:text-amber-400">
            Give this password to {driver.full_name} now
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            It is shown once and cannot be recovered — it is stored only as a hash. If it is lost,
            they can reset it from the sign-in page.
          </p>

          <div className="mt-2 flex items-center gap-2">
            <code className="flex-1 rounded border border-border bg-background px-2 py-1 text-sm">
              {created.temporary_password}
            </code>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                void navigator.clipboard?.writeText(created.temporary_password);
                setCopied(true);
              }}
            >
              {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
              {copied ? 'Copied' : 'Copy'}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  if (driver.account) {
    return (
      <p className="text-sm text-muted-foreground">
        Signs in as <span className="font-medium text-foreground">{driver.account.email}</span>. If
        they have forgotten the password, they can reset it from the sign-in page.
      </p>
    );
  }

  return (
    <form
      className="space-y-3"
      onSubmit={(event) => {
        event.preventDefault();
        creation.mutate({ email });
      }}
    >
      <p className="text-sm text-muted-foreground">
        {driver.full_name} has no login yet, so they cannot open the app. Creating one makes a
        driver account in your company — nothing more.
      </p>

      <div className="space-y-1.5">
        <Label htmlFor="driver-email">Their email</Label>
        <Input
          id="driver-email"
          type="email"
          required
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          placeholder="driver@example.com"
        />
      </div>

      {error ? <p className="text-sm text-destructive">{error.message}</p> : null}

      <Button type="submit" size="sm" loading={creation.isPending}>
        {creation.isPending ? <Loader2 className="animate-spin" aria-hidden /> : <KeyRound aria-hidden />}
        Create login
      </Button>
    </form>
  );
}

function InstallStep() {
  const links = appLinks();
  const signIn = driverSignInUrl();

  return (
    <div className="space-y-4">
      {links.pending ? (
        <div className="flex gap-3 rounded-lg border border-amber-500/40 bg-amber-500/[0.06] p-3">
          <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-500" aria-hidden />
          <div>
            <p className="text-sm font-medium text-amber-800 dark:text-amber-400">
              The app is not on the stores yet
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              FIP Driver has no Google Play or App Store listing at the moment, so it has to be
              installed directly by whoever builds it. Ask your FIP contact for a build for this
              driver&rsquo;s phone.
            </p>
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap gap-2">
          {links.android ? (
            <Button asChild variant="outline" size="sm">
              <a href={links.android} target="_blank" rel="noreferrer noopener">
                <Smartphone aria-hidden />
                Google Play
              </a>
            </Button>
          ) : null}
          {links.ios ? (
            <Button asChild variant="outline" size="sm">
              <a href={links.ios} target="_blank" rel="noreferrer noopener">
                <Smartphone aria-hidden />
                App Store
              </a>
            </Button>
          ) : null}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-4">
        <QrCode value={signIn} />
        <div className="min-w-0">
          <p className="text-sm font-medium">Scan to open FIP on their phone</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Points at the sign-in page, which is the same address whether or not the app is
            installed yet.
          </p>
          <code className="mt-2 block truncate text-xs text-muted-foreground">{signIn}</code>
        </div>
      </div>
    </div>
  );
}

export function DriverSetup({ driver, hasDevice }: { driver: FleetDriver; hasDevice: boolean }) {
  return (
    <div className="space-y-4">
      <StepCard index={1} title="Give them a login" done={Boolean(driver.account)}>
        <AccountStep driver={driver} />
      </StepCard>

      <StepCard index={2} title="Install FIP Driver" done={hasDevice}>
        <InstallStep />
      </StepCard>

      <StepCard index={3} title="Assign them a vehicle" done={Boolean(driver.assigned_vehicle)}>
        {driver.assigned_vehicle ? (
          <p className="text-sm text-muted-foreground">
            Driving{' '}
            <span className="font-medium text-foreground">{driver.assigned_vehicle.plate_number}</span>.
            The app reports against this vehicle.
          </p>
        ) : (
          <p className="text-sm text-muted-foreground">
            The app refuses to guess which vehicle a driver is in, so it stops here until one is
            assigned. Nothing is reported until then.
          </p>
        )}
      </StepCard>

      <StepCard index={4} title="Their phone reports in" done={hasDevice}>
        {hasDevice ? (
          <p className="text-sm text-muted-foreground">
            A handset is registered for {driver.full_name} and is reporting. It appears on device
            health.
          </p>
        ) : (
          <p className="text-sm text-muted-foreground">
            Nothing has reported yet. Once they sign in on the phone and allow location, the device
            registers itself and appears here and on device health — there is nothing further to do
            in this screen.
          </p>
        )}
      </StepCard>
    </div>
  );
}
