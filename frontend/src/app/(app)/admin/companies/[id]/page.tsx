'use client';

import { ArrowLeft, Building2 } from 'lucide-react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { PlanUsage } from '@/components/admin/plan-usage';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useCompany, useUpdateCompany } from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';
import { formatDateTime } from '@/lib/utils';
import type { Company } from '@/types/api';

const TIERS = ['free', 'business', 'enterprise'] as const;

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-border py-2.5 last:border-0">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span className="text-right text-sm">{children ?? '—'}</span>
    </div>
  );
}

function CompanyDetail({ company }: { company: Company }) {
  const update = useUpdateCompany();
  const [tier, setTier] = React.useState(company.subscription_tier);

  // The server is the authority: if it rejects a change, the select must fall
  // back to what the company actually is rather than keep showing the attempt.
  React.useEffect(() => setTier(company.subscription_tier), [company.subscription_tier]);

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
          </CardHeader>
          <CardContent className="space-y-5">
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
                  {TIERS.map((value) => (
                    <option key={value} value={value}>
                      {value}
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
