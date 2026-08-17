'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { FormError } from '@/components/auth/form-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCreateVehicle, useFuelTypes } from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';

/** Mirrors StoreVehicleRequest, so the form rejects what the API would. */
const VEHICLE_TYPES = [
  { value: 'car', label: 'Car' },
  { value: 'suv', label: 'SUV' },
  { value: 'van', label: 'Van' },
  { value: 'motorcycle', label: 'Motorcycle' },
  { value: 'tricycle', label: 'Tricycle' },
  { value: 'jeepney', label: 'Jeepney' },
  { value: 'truck', label: 'Truck' },
  { value: 'bus', label: 'Bus' },
  { value: 'trailer', label: 'Trailer' },
  { value: 'ev', label: 'Electric' },
] as const;

const CURRENT_YEAR = new Date().getFullYear();

const schema = z.object({
  plate_number: z
    .string()
    .min(1, 'Enter the plate number.')
    .max(16, 'That plate number is too long.'),
  nickname: z.string().max(80, 'Keep the nickname under 80 characters.').optional(),
  vehicle_type: z.enum(VEHICLE_TYPES.map((type) => type.value) as [string, ...string[]]),
  fuel_type_id: z.coerce.number().int().positive('Choose a fuel type.'),
  year: z.coerce
    .number()
    .int()
    .min(1950, 'That year looks too early.')
    .max(CURRENT_YEAR + 1, 'That year is in the future.')
    .optional()
    .or(z.literal('').transform(() => undefined)),
  tank_capacity: z.coerce
    .number()
    .positive('Tank capacity must be greater than zero.')
    .optional()
    .or(z.literal('').transform(() => undefined)),
});

type FormValues = z.input<typeof schema>;

export default function NewVehiclePage() {
  const router = useRouter();
  const creation = useCreateVehicle();
  const { data: fuelTypes, isLoading: fuelTypesLoading } = useFuelTypes();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { vehicle_type: 'car' },
  });

  const error = creation.error instanceof ApiError ? creation.error : null;

  return (
    <div className="mx-auto w-full max-w-2xl space-y-6">
      <div>
        <Button asChild variant="ghost" size="sm" className="-ml-2">
          <Link href="/vehicles">
            <ArrowLeft aria-hidden="true" />
            Vehicles
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <h1 className="text-lg font-semibold leading-none tracking-tight">Add a vehicle</h1>
          <p className="text-sm text-muted-foreground">
            Fuel type and plate number are all that is needed; the rest sharpens the efficiency
            figures.
          </p>
        </CardHeader>

        <CardContent>
          <form
            className="space-y-5"
            onSubmit={handleSubmit(async (values) => {
              const vehicle = await creation.mutateAsync({
                ...values,
                nickname: values.nickname || undefined,
              });

              router.push(`/vehicles?added=${vehicle.id}`);
            })}
          >
            {error ? <FormError>{error.message}</FormError> : null}

            <div className="grid gap-5 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="plate_number">Plate number</Label>
                <Input id="plate_number" placeholder="ABC 1234" {...register('plate_number')} />
                {errors.plate_number ? (
                  <p className="text-sm text-destructive">{errors.plate_number.message}</p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="nickname">
                  Nickname <span className="text-muted-foreground">(optional)</span>
                </Label>
                <Input id="nickname" placeholder="The blue one" {...register('nickname')} />
                {errors.nickname ? (
                  <p className="text-sm text-destructive">{errors.nickname.message}</p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="vehicle_type">Type</Label>
                <select
                  id="vehicle_type"
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                  {...register('vehicle_type')}
                >
                  {VEHICLE_TYPES.map((type) => (
                    <option key={type.value} value={type.value}>
                      {type.label}
                    </option>
                  ))}
                </select>
                {errors.vehicle_type ? (
                  <p className="text-sm text-destructive">{errors.vehicle_type.message}</p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="fuel_type_id">Fuel type</Label>
                <select
                  id="fuel_type_id"
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                  disabled={fuelTypesLoading}
                  defaultValue=""
                  {...register('fuel_type_id')}
                >
                  {/* An empty default rather than silently pre-selecting the
                      first entry: the mobile sheet defaulted a value it never
                      recorded, and refused to save while showing a selection. */}
                  <option value="" disabled>
                    {fuelTypesLoading ? 'Loading…' : 'Choose a fuel type'}
                  </option>
                  {fuelTypes?.map((fuelType) => (
                    <option key={fuelType.id} value={fuelType.id}>
                      {fuelType.name}
                    </option>
                  ))}
                </select>
                {errors.fuel_type_id ? (
                  <p className="text-sm text-destructive">{errors.fuel_type_id.message}</p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="year">
                  Year <span className="text-muted-foreground">(optional)</span>
                </Label>
                <Input
                  id="year"
                  type="number"
                  inputMode="numeric"
                  placeholder={String(CURRENT_YEAR)}
                  {...register('year')}
                />
                {errors.year ? (
                  <p className="text-sm text-destructive">{errors.year.message}</p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="tank_capacity">
                  Tank capacity <span className="text-muted-foreground">(optional, litres)</span>
                </Label>
                <Input
                  id="tank_capacity"
                  type="number"
                  step="0.1"
                  inputMode="decimal"
                  placeholder="45"
                  {...register('tank_capacity')}
                />
                {errors.tank_capacity ? (
                  <p className="text-sm text-destructive">{errors.tank_capacity.message}</p>
                ) : null}
              </div>
            </div>

            <div className="flex justify-end gap-2">
              <Button asChild variant="outline" type="button">
                <Link href="/vehicles">Cancel</Link>
              </Button>
              <Button type="submit" disabled={creation.isPending}>
                {creation.isPending ? 'Saving…' : 'Add vehicle'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
