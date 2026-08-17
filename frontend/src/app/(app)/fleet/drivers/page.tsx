'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft, IdCard, Plus, UserRound } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { RequireRole } from '@/components/auth/require-role';
import { FormError } from '@/components/auth/form-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useCompanies, useCreateDriver, useFleetDrivers, useUpdateDriver } from '@/hooks/use-api';
import { useAuth } from '@/hooks/use-auth';
import { ApiError } from '@/lib/api-client';
import type { FleetDriver } from '@/types/api';

/**
 * Mirrors StoreDriverRequest.
 *
 * company_id is required of a platform administrator and absent for everyone
 * else, because that is exactly what the API asks for. A tenant user's company
 * is inferred from the caller and naming another one is refused, so a field
 * would only invite a 403. A platform administrator has no company to infer,
 * so without the field the form could not be satisfied at all — it used to
 * write a driver belonging to nobody, and now gets a `company_required` 422.
 */
const makeSchema = (requiresCompany: boolean) =>
  z.object({
    first_name: z.string().min(1, 'Enter a first name.').max(80),
    last_name: z.string().min(1, 'Enter a last name.').max(80),
    employee_no: z.string().max(40).optional(),
    phone: z.string().max(32).optional(),
    licence_number: z.string().max(40).optional(),
    licence_expiry: z.string().optional(),
    company_id: requiresCompany
      ? z.string().min(1, 'Choose the company this driver belongs to.')
      : z.string().optional(),
  });

type FormValues = z.infer<ReturnType<typeof makeSchema>>;

const STATUS_TONE: Record<string, 'secondary' | 'destructive'> = {
  inactive: 'secondary',
  suspended: 'destructive',
};

function DriverForm({ onDone }: { onDone: () => void }) {
  const creation = useCreateDriver();

  // Matches the server's own test: isPlatformAdministrator() is super_admin or
  // system_admin by role, whatever company the row happens to carry.
  const { isAdmin } = useAuth();
  const { data: companies } = useCompanies({}, isAdmin);

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(React.useMemo(() => makeSchema(isAdmin), [isAdmin])),
  });

  const error = creation.error instanceof ApiError ? creation.error : null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Add a driver</CardTitle>
        <p className="text-sm text-muted-foreground">
          A driver record can exist before the person has a login, which is how a fleet is entered
          before anyone is onboarded.
        </p>
      </CardHeader>
      <CardContent>
        <form
          className="space-y-5"
          onSubmit={handleSubmit(async (values) => {
            await creation.mutateAsync({
              ...values,
              employee_no: values.employee_no || undefined,
              phone: values.phone || undefined,
              licence_number: values.licence_number || undefined,
              licence_expiry: values.licence_expiry || undefined,
              // Sent only when it was asked for; a tenant user naming a company
              // is refused even when the company is their own.
              company_id: values.company_id ? Number(values.company_id) : undefined,
            });

            reset();
            onDone();
          })}
        >
          {error ? <FormError>{error.message}</FormError> : null}

          <div className="grid gap-5 sm:grid-cols-2">
            {isAdmin ? (
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="company_id">Company</Label>
                <select
                  id="company_id"
                  className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                  {...register('company_id')}
                >
                  <option value="">Choose a company…</option>
                  {(companies ?? []).map((company) => (
                    <option key={company.id} value={company.id}>
                      {company.name}
                    </option>
                  ))}
                </select>
                {errors.company_id ? (
                  <p className="text-sm text-destructive">{errors.company_id.message}</p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    You administer every tenant, so there is no company to assume. A driver without
                    one would be visible to nobody.
                  </p>
                )}
              </div>
            ) : null}

            <div className="space-y-2">
              <Label htmlFor="first_name">First name</Label>
              <Input id="first_name" {...register('first_name')} />
              {errors.first_name ? (
                <p className="text-sm text-destructive">{errors.first_name.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="last_name">Last name</Label>
              <Input id="last_name" {...register('last_name')} />
              {errors.last_name ? (
                <p className="text-sm text-destructive">{errors.last_name.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="employee_no">Employee number</Label>
              <Input id="employee_no" {...register('employee_no')} />
            </div>

            <div className="space-y-2">
              <Label htmlFor="phone">Phone</Label>
              <Input id="phone" {...register('phone')} />
            </div>

            <div className="space-y-2">
              <Label htmlFor="licence_number">Licence number</Label>
              <Input id="licence_number" {...register('licence_number')} />
            </div>

            <div className="space-y-2">
              <Label htmlFor="licence_expiry">Licence expiry</Label>
              <Input id="licence_expiry" type="date" {...register('licence_expiry')} />
            </div>
          </div>

          <div className="flex justify-end gap-3">
            <Button variant="ghost" type="button" onClick={onDone}>
              Cancel
            </Button>
            <Button type="submit" disabled={creation.isPending}>
              {creation.isPending ? 'Adding…' : 'Add driver'}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function DriverRow({ driver }: { driver: FleetDriver }) {
  const update = useUpdateDriver();
  const assigned = driver.assigned_vehicle?.plate_number;

  return (
    <div className="flex items-center gap-4 border-b border-border px-4 py-3 last:border-0">
      <UserRound className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium">{driver.full_name}</span>
          {driver.status !== 'active' && (
            <Badge variant={STATUS_TONE[driver.status] ?? 'secondary'} className="capitalize">
              {driver.status}
            </Badge>
          )}
        </div>
        <p className="truncate text-sm text-muted-foreground">
          {driver.employee_no ? `#${driver.employee_no}` : 'No employee number'}
          {assigned ? ` · ${assigned}` : ' · No vehicle'}
        </p>
      </div>

      <div className="hidden w-40 shrink-0 text-sm text-muted-foreground sm:block">
        {driver.licence.number ?? 'No licence on file'}
      </div>

      {/*
        Suspending is the one edit worth having inline: it is what a manager
        reaches for when somebody stops driving, and it is reversible.
      */}
      <Button
        variant="ghost"
        size="sm"
        disabled={update.isPending}
        onClick={() =>
          update.mutate({
            id: driver.id,
            status: driver.status === 'active' ? 'inactive' : 'active',
          })
        }
      >
        {driver.status === 'active' ? 'Deactivate' : 'Reactivate'}
      </Button>
    </div>
  );
}

function DriversPage() {
  const [adding, setAdding] = React.useState(false);
  const { data, isLoading, isError } = useFleetDrivers();
  const drivers = data ?? [];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Link
            href="/fleet"
            className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden />
            Fleet
          </Link>
          <h1 className="text-2xl font-semibold">Drivers</h1>
          <p className="text-sm text-muted-foreground">
            The people who drive your vehicles, and the licences behind them.
          </p>
        </div>

        {!adding && (
          <Button onClick={() => setAdding(true)}>
            <Plus aria-hidden />
            Add driver
          </Button>
        )}
      </div>

      {adding && <DriverForm onDone={() => setAdding(false)} />}

      {isLoading && <Skeleton className="h-64 w-full" />}

      {isError && (
        <EmptyState
          icon={IdCard}
          title="Could not load drivers"
          description="The roster could not be fetched. Reload the page to try again."
        />
      )}

      {!isLoading && !isError && drivers.length === 0 && !adding && (
        <EmptyState
          icon={IdCard}
          title="No drivers yet"
          description="Add a driver to assign them a vehicle and see their fuel and efficiency figures."
        />
      )}

      {drivers.length > 0 && (
        <Card>
          <CardContent className="p-0">
            {drivers.map((driver) => (
              <DriverRow key={driver.id} driver={driver} />
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

export default function Page() {
  return (
    <RequireRole roles={['fleet_manager', 'company_manager', 'super_admin', 'system_admin']}>
      <DriversPage />
    </RequireRole>
  );
}
