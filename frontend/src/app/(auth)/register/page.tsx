'use client';

import { ArrowRight, Building2, Rocket, Users } from 'lucide-react';
import Link from 'next/link';

import { AuthShell } from '@/components/auth/auth-shell';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { usePlans } from '@/hooks/use-api';
import { cn } from '@/lib/utils';
import type { Plan } from '@/types/api';

/**
 * Choosing an account path.
 *
 * Every number on this page comes from the API. A registration page that
 * carried its own limits would be a second copy of them, and the two would
 * drift the first time the business changed one — so the cards render what the
 * backend returns, including the fact that the figures are provisional.
 *
 * There is no pricing here because none exists. Inventing a figure on the page
 * a customer signs up from would be the worst possible place to invent one.
 */

const ICONS: Record<string, typeof Rocket> = {
  free_trial: Rocket,
  business: Building2,
  enterprise: Users,
};

function limitLine(value: number | null, noun: string): string {
  if (value === null) return `${noun}: no limit`;

  return `${value} ${value === 1 ? noun.replace(/s$/, '') : noun}`;
}

function PlanCard({ plan, trialDays }: { plan: Plan; trialDays: number }) {
  const Icon = ICONS[plan.name] ?? Building2;

  return (
    <div
      className={cn(
        'flex flex-col rounded-xl border p-5 transition',
        plan.name === 'business' ? 'border-primary/40 bg-primary/[0.03]' : 'border-border',
      )}
    >
      <Icon className="size-5 text-muted-foreground" aria-hidden />

      <h2 className="mt-3 text-lg font-semibold">{plan.label}</h2>
      <p className="mt-1 text-sm text-muted-foreground">{plan.description}</p>

      <ul className="mt-4 flex-1 space-y-1.5 text-sm text-muted-foreground">
        <li>{limitLine(plan.limits.vehicles, 'vehicles')}</li>
        <li>{limitLine(plan.limits.users, 'people')}</li>
        <li>{limitLine(plan.limits.devices, 'devices')}</li>
        {plan.name === 'free_trial' && <li>{trialDays} days</li>}
        {plan.requires_confirmation && <li>Limits agreed with us first</li>}
      </ul>

      <Button asChild className="mt-5 w-full self-end" variant={plan.name === 'business' ? 'default' : 'outline'}>
        <Link href={`/register/company?plan=${plan.name}`}>
          Choose
          <ArrowRight aria-hidden />
        </Link>
      </Button>
    </div>
  );
}

export default function ChoosePlanPage() {
  const { data, isLoading, isError } = usePlans();

  const selectable = (data?.plans ?? []).filter((plan) => plan.selectable);

  return (
    <AuthShell
      wide
      title="Choose your account"
      description="Fleet management for operators of any size. Start with a trial, or go straight to a plan."
      footer={
        <>
          Already have an account?{' '}
          <Link href="/login" className="font-medium text-primary hover:underline">
            Sign in
          </Link>
        </>
      }
    >
      <div className="space-y-4">
        {isLoading && (
          <div className="grid gap-4 sm:grid-cols-3">
            {[0, 1, 2].map((key) => (
              <Skeleton key={key} className="h-64 w-full rounded-xl" />
            ))}
          </div>
        )}

        {isError && (
          <EmptyState
            icon={Building2}
            title="Could not load the plans"
            description="This is a connection problem rather than an empty list. Reload to try again."
          />
        )}

        {!isLoading && !isError && (
          <>
            <div className="grid gap-4 sm:grid-cols-3">
              {selectable.map((plan) => (
                <PlanCard key={plan.name} plan={plan} trialDays={data?.trial_days ?? 0} />
              ))}
            </div>

            {data?.is_provisional && (
              // Said plainly rather than buried: these numbers are placeholders
              // awaiting a business decision, and somebody signing up should
              // not read them as a commercial commitment.
              <p className="text-center text-xs text-muted-foreground">
                Allowances shown are indicative and not final commercial terms.
              </p>
            )}

            <p className="text-center text-sm text-muted-foreground">
              Not managing a fleet?{' '}
              <Link
                href="/register/personal"
                className="font-medium text-primary hover:underline"
              >
                Create a personal account
              </Link>
            </p>
          </>
        )}
      </div>
    </AuthShell>
  );
}
