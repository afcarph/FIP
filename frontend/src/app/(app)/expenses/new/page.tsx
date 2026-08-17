'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft, Car } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { FormError } from '@/components/auth/form-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { ReceiptScanner } from '@/components/fleet/receipt-scanner';
import { useLogFillUp, useVehicles } from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';
import { formatCurrency } from '@/lib/utils';

const schema = z.object({
  vehicle_id: z.coerce.number().int().positive('Choose a vehicle.'),
  litres: z.coerce
    .number()
    .min(0.001, 'Enter how many litres you put in.')
    .max(5000, 'That is more than a tanker holds.'),
  price_per_litre: z.coerce
    .number()
    .min(0.01, 'Enter the price per litre.')
    .max(999.99, 'That price looks wrong.'),
  odometer: z.coerce
    .number()
    .min(0)
    .max(9999999)
    .optional()
    .or(z.literal('').transform(() => undefined)),
  is_full_tank: z.boolean(),
  notes: z.string().max(255, 'Keep notes under 255 characters.').optional(),
});

type FormValues = z.input<typeof schema>;

export default function NewFillUpPage() {
  const router = useRouter();
  const logFillUp = useLogFillUp();
  const { data: vehicles, isLoading: vehiclesLoading } = useVehicles();

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { is_full_tank: true },
  });

  // Shown live so a mistyped price is obvious before it is saved.
  const litres = Number(watch('litres')) || 0;
  const pricePerLitre = Number(watch('price_per_litre')) || 0;
  const total = litres * pricePerLitre;

  const error = logFillUp.error instanceof ApiError ? logFillUp.error : null;

  if (vehiclesLoading) {
    return <Skeleton className="h-96 w-full" />;
  }

  // Without a vehicle there is nothing to attach a fill-up to, and the API
  // requires vehicle_id — so send them where they can fix that rather than
  // presenting a form that cannot succeed.
  if (!vehicles?.length) {
    return (
      <div className="mx-auto w-full max-w-2xl">
        <EmptyState
          icon={Car}
          title="Add a vehicle first"
          description="A fill-up is recorded against a vehicle, so there needs to be one to log against."
          action={
            <Button asChild size="sm">
              <Link href="/vehicles/new">Add a vehicle</Link>
            </Button>
          }
        />
      </div>
    );
  }

  // .at() rather than [0]: with noUncheckedIndexedAccess a length check does
  // not narrow an index, and the default has to be a real option value or the
  // select shows a vehicle the form state does not hold.
  const onlyVehicle = vehicles.length === 1 ? vehicles.at(0) : undefined;

  return (
    <div className="mx-auto w-full max-w-2xl space-y-6">
      <div>
        <Button asChild variant="ghost" size="sm" className="-ml-2">
          <Link href="/expenses">
            <ArrowLeft aria-hidden="true" />
            Fuel expenses
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <h1 className="text-lg font-semibold leading-none tracking-tight">Log a fill-up</h1>
          <p className="text-sm text-muted-foreground">
            The odometer is optional, but without it efficiency cannot be worked out.
          </p>
        </CardHeader>

        <CardContent>
          <form
            className="space-y-5"
            onSubmit={handleSubmit(async (values) => {
              await logFillUp.mutateAsync({
                ...values,
                notes: values.notes || undefined,
              });

              router.push('/expenses');
            })}
          >
            {error ? <FormError>{error.message}</FormError> : null}

            {/* Above the fields on purpose: scanning is the fast path, and a
                control offered after the work is done gets used by nobody. */}
            <ReceiptScanner
              vehicleId={Number(watch('vehicle_id')) || undefined}
              onScanned={(result) => {
                const { draft } = result;

                // Only fields the scan actually read are written, so a partial
                // read never blanks something the user already typed.
                if (draft.litres != null) setValue('litres', draft.litres);
                if (draft.price_per_litre != null) setValue('price_per_litre', draft.price_per_litre);
                if (draft.odometer != null) setValue('odometer', draft.odometer);
                if (draft.station_hint) setValue('notes', draft.station_hint);
              }}
            />

            <div className="space-y-2">
              <Label htmlFor="vehicle_id">Vehicle</Label>
              <select
                id="vehicle_id"
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                defaultValue={onlyVehicle ? String(onlyVehicle.id) : ''}
                {...register('vehicle_id')}
              >
                {/* Defaulted only when there is exactly one, and the value is a
                    real option so the form state matches what is displayed. */}
                {onlyVehicle ? null : (
                  <option value="" disabled>
                    Choose a vehicle
                  </option>
                )}
                {vehicles.map((vehicle) => (
                  <option key={vehicle.id} value={vehicle.id}>
                    {vehicle.display_name} · {vehicle.plate_number}
                  </option>
                ))}
              </select>
              {errors.vehicle_id ? (
                <p className="text-sm text-destructive">{errors.vehicle_id.message}</p>
              ) : null}
            </div>

            <div className="grid gap-5 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="litres">Litres</Label>
                <Input
                  id="litres"
                  type="number"
                  step="0.01"
                  inputMode="decimal"
                  placeholder="40"
                  {...register('litres')}
                />
                {errors.litres ? (
                  <p className="text-sm text-destructive">{errors.litres.message}</p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="price_per_litre">Price per litre</Label>
                <Input
                  id="price_per_litre"
                  type="number"
                  step="0.01"
                  inputMode="decimal"
                  placeholder="65.50"
                  {...register('price_per_litre')}
                />
                {errors.price_per_litre ? (
                  <p className="text-sm text-destructive">{errors.price_per_litre.message}</p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="odometer">
                  Odometer <span className="text-muted-foreground">(optional, km)</span>
                </Label>
                <Input
                  id="odometer"
                  type="number"
                  step="0.1"
                  inputMode="decimal"
                  placeholder="12345"
                  {...register('odometer')}
                />
                {errors.odometer ? (
                  <p className="text-sm text-destructive">{errors.odometer.message}</p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="notes">
                  Notes <span className="text-muted-foreground">(optional)</span>
                </Label>
                <Input id="notes" placeholder="Shell BGC" {...register('notes')} />
                {errors.notes ? (
                  <p className="text-sm text-destructive">{errors.notes.message}</p>
                ) : null}
              </div>
            </div>

            <label className="flex items-center gap-3 text-sm">
              <input
                type="checkbox"
                className="size-4 rounded border-input"
                {...register('is_full_tank')}
              />
              Filled the tank
              <span className="text-muted-foreground">
                — partial fills cannot be used for efficiency
              </span>
            </label>

            <div className="flex items-center justify-between rounded-lg bg-muted px-4 py-3">
              <span className="text-sm text-muted-foreground">Total</span>
              <span className="text-lg font-semibold tabular-nums">{formatCurrency(total)}</span>
            </div>

            <div className="flex justify-end gap-2">
              <Button asChild variant="outline" type="button">
                <Link href="/expenses">Cancel</Link>
              </Button>
              <Button type="submit" disabled={logFillUp.isPending}>
                {logFillUp.isPending ? 'Saving…' : 'Save fill-up'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
