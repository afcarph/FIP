'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { Building2, Loader2 } from 'lucide-react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { AuthShell } from '@/components/auth/auth-shell';
import { FormError } from '@/components/auth/form-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { usePlans } from '@/hooks/use-api';
import { useRegisterCompany } from '@/hooks/use-auth';
import { ApiError } from '@/lib/api-client';
import { PASSWORD_MIN_LENGTH, withPasswordConfirmation } from '@/lib/validation';
import type { Plan } from '@/types/api';

/**
 * Registering a fleet, in two steps: which plan, then who you are.
 *
 * The plan is carried in the URL rather than in component state so a chosen
 * plan survives a reload and can be linked to directly. It is still only a
 * request — the server validates it against its own configured tiers and
 * decides what it means, and this page never sends a limit.
 */

function planLimit(value: number | null): string {
  return value === null ? 'No limit' : String(value);
}

function PlanSummary({ plan, trialDays }: { plan: Plan; trialDays: number }) {
  return (
    <div className="rounded-lg border border-border bg-muted/40 p-4">
      <div className="flex items-center justify-between gap-3">
        <div>
          <p className="font-medium">{plan.label}</p>
          <p className="text-sm text-muted-foreground">{plan.description}</p>
        </div>
        <Link
          href="/register"
          className="shrink-0 text-sm underline underline-offset-2 hover:text-foreground"
        >
          Change
        </Link>
      </div>

      <dl className="mt-3 grid grid-cols-3 gap-2 text-sm">
        <div>
          <dt className="text-xs text-muted-foreground">Vehicles</dt>
          <dd className="font-medium tabular-nums">{planLimit(plan.limits.vehicles)}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">People</dt>
          <dd className="font-medium tabular-nums">{planLimit(plan.limits.users)}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Devices</dt>
          <dd className="font-medium tabular-nums">{planLimit(plan.limits.devices)}</dd>
        </div>
      </dl>

      {plan.name === 'free_trial' && (
        <p className="mt-3 text-xs text-muted-foreground">
          The trial runs for {trialDays} days. Nothing is deleted when it ends.
        </p>
      )}

      {plan.requires_confirmation && (
        <p className="mt-3 text-xs text-muted-foreground">
          Enterprise limits are agreed with us first. Your account is created straight away and runs
          on the standard allowance until we confirm them.
        </p>
      )}
    </div>
  );
}

const schema = withPasswordConfirmation({
  company_name: z.string().min(1, 'Enter your company name.').max(180),
  first_name: z.string().min(1, 'Enter your first name.').max(80),
  last_name: z.string().min(1, 'Enter your last name.').max(80),
  email: z.string().email('Enter a valid email address.').max(180),
  phone: z
    .string()
    .regex(/^\+?[0-9\s\-()]{7,32}$/, 'Enter a valid phone number, or leave it blank.')
    .optional()
    .or(z.literal('')),
  accepts_terms: z.literal(true, {
    errorMap: () => ({ message: 'You must accept the terms to register.' }),
  }),
});

type FormValues = z.infer<typeof schema>;

function RegisterCompanyForm() {
  const router = useRouter();
  const params = useSearchParams();
  const requested = params.get('plan');

  const { data: catalogue, isLoading } = usePlans();
  const registration = useRegisterCompany();

  const plan = React.useMemo(
    () => catalogue?.plans.find((candidate) => candidate.name === requested) ?? null,
    [catalogue, requested],
  );

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const error = registration.error instanceof ApiError ? registration.error : null;

  // A plan that does not exist is a bad link, not a form to fill in.
  React.useEffect(() => {
    if (!isLoading && catalogue && !plan) router.replace('/register');
  }, [isLoading, catalogue, plan, router]);

  if (isLoading || !plan || !catalogue) {
    return (
      <AuthShell title="Create your fleet account" description="Loading the plan you chose">
        <Skeleton className="h-64 w-full" />
      </AuthShell>
    );
  }

  return (
    <AuthShell
      title="Create your fleet account"
      description="Your company, and the administrator who will run it"
      footer={
        <>
          Already have an account?{' '}
          <Link href="/login" className="font-medium text-primary hover:underline">
            Sign in
          </Link>
        </>
      }
    >
      <form
        className="space-y-4"
        onSubmit={handleSubmit((values) =>
          registration.mutate({
            plan: plan.name,
            company: { name: values.company_name },
            admin: {
              first_name: values.first_name,
              last_name: values.last_name,
              email: values.email,
              phone: values.phone || undefined,
              password: values.password,
              password_confirmation: values.password_confirmation,
            },
            accepts_terms: true,
          }),
        )}
      >
        <PlanSummary plan={plan} trialDays={catalogue.trial_days} />

        {error ? <FormError>{error.message}</FormError> : null}

        <div className="space-y-2">
          <Label htmlFor="company_name">Company name</Label>
          <Input id="company_name" autoComplete="organization" {...register('company_name')} />
          {errors.company_name ? (
            <p className="text-sm text-destructive">{errors.company_name.message}</p>
          ) : null}
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="first_name">First name</Label>
            <Input id="first_name" autoComplete="given-name" {...register('first_name')} />
            {errors.first_name ? (
              <p className="text-sm text-destructive">{errors.first_name.message}</p>
            ) : null}
          </div>

          <div className="space-y-2">
            <Label htmlFor="last_name">Last name</Label>
            <Input id="last_name" autoComplete="family-name" {...register('last_name')} />
            {errors.last_name ? (
              <p className="text-sm text-destructive">{errors.last_name.message}</p>
            ) : null}
          </div>
        </div>

        <div className="space-y-2">
          <Label htmlFor="email">Work email</Label>
          <Input id="email" type="email" autoComplete="email" {...register('email')} />
          {errors.email ? <p className="text-sm text-destructive">{errors.email.message}</p> : null}
        </div>

        <div className="space-y-2">
          <Label htmlFor="phone">Phone (optional)</Label>
          <Input id="phone" autoComplete="tel" {...register('phone')} />
          {errors.phone ? <p className="text-sm text-destructive">{errors.phone.message}</p> : null}
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="password">Password</Label>
            <Input
              id="password"
              type="password"
              autoComplete="new-password"
              {...register('password')}
            />
            {errors.password ? (
              <p className="text-sm text-destructive">{errors.password.message}</p>
            ) : (
              <p className="text-xs text-muted-foreground">
                At least {PASSWORD_MIN_LENGTH} characters, with upper and lower case, a number and a
                symbol.
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="password_confirmation">Confirm password</Label>
            <Input
              id="password_confirmation"
              type="password"
              autoComplete="new-password"
              {...register('password_confirmation')}
            />
            {errors.password_confirmation ? (
              <p className="text-sm text-destructive">{errors.password_confirmation.message}</p>
            ) : null}
          </div>
        </div>

        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" {...register('accepts_terms')} />
          <span>I accept the terms of service and the privacy policy.</span>
        </label>
        {errors.accepts_terms ? (
          <p className="text-sm text-destructive">{errors.accepts_terms.message}</p>
        ) : null}

        <Button type="submit" className="w-full" loading={registration.isPending}>
          {registration.isPending ? (
            <Loader2 className="animate-spin" aria-hidden />
          ) : (
            <Building2 aria-hidden />
          )}
          Create account
        </Button>

      </form>
    </AuthShell>
  );
}

/**
 * The plan is read from the query string, and `useSearchParams` opts a page out
 * of static rendering unless it sits behind a Suspense boundary — the build
 * refuses to prerender otherwise. The fallback is the same skeleton the form
 * shows while the plans load, so the boundary is invisible.
 */
export default function RegisterCompanyPage() {
  return (
    <React.Suspense
      fallback={
        <AuthShell title="Create your fleet account" description="Loading the plan you chose">
          <Skeleton className="h-64 w-full" />
        </AuthShell>
      }
    >
      <RegisterCompanyForm />
    </React.Suspense>
  );
}
