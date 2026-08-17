'use client';

import { ArrowLeft, Building2 } from 'lucide-react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { PlanUsage } from '@/components/admin/plan-usage';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useCompany, useSubscriptionTiers, useUpdateCompany } from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';
import { formatDateTime, formatRelative } from '@/lib/utils';
import type { SubscriptionReport } from '@/types/api';
import type { Company } from '@/types/api';

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-border py-2.5 last:border-0">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span className="text-right text-sm">{children ?? '—'}</span>
    </div>
  );
}

/**
 * Where the subscription stands, in a sentence.
 *
 * The plan alone does not answer what an administrator opened this page to
 * find out — whether it is running, on trial, lapsed, or waiting on them.
 */
function statusLine(report: SubscriptionReport): string {
  const plan = report.tier.replace(/_/g, ' ');

  if (report.status === 'pending_setup') return `${plan} · awaiting confirmation`;
  if (report.trial_expired) return `${plan} · trial ended`;
  if (report.status === 'trialing' && report.trial_ends_at) {
    return `${plan} · trial ends ${formatRelative(report.trial_ends_at)}`;
  }
  if (report.has_negotiated_limits) return `${plan} · negotiated limits`;

  return `${plan} · active`;
}

function CompanyDetail({ company }: { company: Company }) {
  const update = useUpdateCompany();
  // Served rather than hardcoded, so this select cannot drift from what the
  // API will accept.
  const { data: tierData } = useSubscriptionTiers();
  const tiers = tierData?.tiers ?? [];
  const [tier, setTier] = React.useState(company.subscription_tier);

  // The server is the authority: if it rejects a change, the select must fall
  // back to what the company actually is rather than keep showing the attempt.
  React.useEffect(() => setTier(company.subscription_tier), [company.subscription_tier]);

  const subscription = company.subscription;

  // Strings rather than numbers so an empty field is distinguishable from a
  // zero: empty means "no limit on this resource", which is a real answer.
  const [negotiated, setNegotiated] = React.useState({ vehicles: '', seats: '', devices: '' });

  React.useEffect(() => {
    const limits = subscription?.has_negotiated_limits ? subscription.resources : null;

    if (!limits) return;

    setNegotiated({
      vehicles: limits.vehicles.limit === null ? '' : String(limits.vehicles.limit),
      seats: limits.seats.limit === null ? '' : String(limits.seats.limit),
      devices: limits.devices.limit === null ? '' : String(limits.devices.limit),
    });
  }, [subscription]);

  const error = update.error instanceof ApiError ? update.error : null;
  const dirty = tier !== company.subscription_tier;

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/admin/companies"
          className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          Companies
        </Link>

        <div className="flex flex-wrap items-center gap-2">
          <h1 className="text-2xl font-semibold">{company.name}</h1>
          {!company.is_active && <Badge variant="secondary">Inactive</Badge>}
        </div>
        <p className="text-sm text-muted-foreground">{company.legal_name ?? 'No registered name'}</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Plan</CardTitle>
            {subscription ? (
              <CardDescription>
                {statusLine(subscription)}
              </CardDescription>
            ) : null}
          </CardHeader>
          <CardContent className="space-y-5">
            {/*
              An enterprise agreement is the one plan change that needs a
              number rather than a selection, and until somebody supplies it
              the tenant is running on an interim allowance. Surfaced here
              because this page is where that gets resolved.
            */}
            {subscription?.status === 'pending_setup' ? (
              <div className="rounded-lg border border-amber-500/40 bg-amber-500/[0.06] p-3">
                <p className="text-sm font-medium text-amber-800 dark:text-amber-400">
                  Awaiting confirmation
                </p>
                <p className="mt-1 text-sm text-muted-foreground">
                  They chose {company.subscription_tier.replace(/_/g, ' ')} and are running on the{' '}
                  {subscription.effective_tier?.replace(/_/g, ' ')} allowance meanwhile. Set the
                  agreed limits below to make it live.
                </p>
              </div>
            ) : null}

            {company.subscription ? (
              <PlanUsage report={company.subscription} />
            ) : (
              <p className="text-sm text-muted-foreground">Usage is unavailable.</p>
            )}

            <div className="space-y-2 border-t border-border pt-4">
              <label htmlFor="tier" className="text-sm font-medium">
                Change plan
              </label>
              <div className="flex gap-2">
                <select
                  id="tier"
                  value={tier}
                  onChange={(event) => setTier(event.target.value)}
                  className="h-9 flex-1 rounded-md border border-input bg-transparent px-3 text-sm capitalize"
                >
                  {tiers.map((option) => (
                    <option key={option.name} value={option.name}>
                      {option.label}
                    </option>
                  ))}
                </select>
                <Button
                  disabled={!dirty || update.isPending}
                  onClick={() => update.mutate({ id: company.id, subscription_tier: tier })}
                >
                  {update.isPending ? 'Saving…' : 'Save'}
                </Button>
              </div>

              {/*
                Said plainly because it is the question an operator asks before
                clicking: reducing a plan below current usage is allowed, and
                nothing already created is removed.
              */}
              <p className="text-xs text-muted-foreground">
                Lowering a plan never removes anything. Existing vehicles, seats and devices stay;
                only new ones are refused.
              </p>

              {error ? <p className="text-sm text-destructive">{error.message}</p> : null}
            </div>

            <div className="space-y-2 border-t border-border pt-4">
              <p className="text-sm font-medium">Negotiated limits</p>
              <p className="text-xs text-muted-foreground">
                For an agreement that is not what the plan says. Leave a field empty for no limit on
                that resource. Saving these confirms the plan and replaces the interim allowance.
              </p>

              <div className="grid grid-cols-3 gap-2">
                {(['vehicles', 'seats', 'devices'] as const).map((key) => (
                  <div key={key} className="space-y-1">
                    <label htmlFor={`limit-${key}`} className="text-xs capitalize text-muted-foreground">
                      {key}
                    </label>
                    <input
                      id={`limit-${key}`}
                      type="number"
                      min={0}
                      inputMode="numeric"
                      placeholder="No limit"
                      value={negotiated[key]}
                      onChange={(event) =>
                        setNegotiated((current) => ({ ...current, [key]: event.target.value }))
                      }
                      className="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                    />
                  </div>
                ))}
              </div>

              <Button
                variant="outline"
                size="sm"
                disabled={update.isPending}
                onClick={() =>
                  update.mutate({
                    id: company.id,
                    subscription_tier: tier,
                    // An empty field is a deliberate "no limit", which the API
                    // stores as null — not the same as omitting the resource.
                    subscription_limits: {
                      vehicles: negotiated.vehicles === '' ? null : Number(negotiated.vehicles),
                      seats: negotiated.seats === '' ? null : Number(negotiated.seats),
                      devices: negotiated.devices === '' ? null : Number(negotiated.devices),
                    },
                  })
                }
              >
                {update.isPending ? 'Saving…' : 'Confirm agreed limits'}
              </Button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Details</CardTitle>
          </CardHeader>
          <CardContent>
            <Field label="Contact email">{company.contact_email}</Field>
            <Field label="Contact phone">{company.contact_phone}</Field>
            <Field label="TIN">{company.tin}</Field>
            <Field label="Industry">{company.industry}</Field>
            <Field label="Users">{company.counts?.users ?? 0}</Field>
            <Field label="Vehicles">{company.counts?.vehicles ?? 0}</Field>
            <Field label="Drivers">{company.counts?.drivers ?? 0}</Field>
            <Field label="Created">
              {company.created_at ? formatDateTime(company.created_at) : '—'}
            </Field>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

function CompanyDetailPage() {
  const params = useParams<{ id: string }>();
  const { data, isLoading, isError } = useCompany(Number(params?.id));

  if (isLoading) return <Skeleton className="h-96 w-full" />;

  if (isError || !data) {
    return (
      <EmptyState
        icon={Building2}
        title="Company not available"
        description="This company could not be loaded. It may not exist, or you may not have access to it."
      />
    );
  }

  return <CompanyDetail company={data} />;
}

export default function Page() {
  return (
    <RequireRole roles={['super_admin', 'system_admin']}>
      <CompanyDetailPage />
    </RequireRole>
  );
}
