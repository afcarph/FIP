'use client';

import { ArrowLeft, Building2, Plus, Search } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { RequireRole } from '@/components/auth/require-role';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useCompanies } from '@/hooks/use-api';
import type { Company } from '@/types/api';

function CompanyRow({ company }: { company: Company }) {
  return (
    <Link
      href={`/admin/companies/${company.id}`}
      className="flex items-center gap-4 border-b border-border px-4 py-3 last:border-0 hover:bg-muted/50"
    >
      <Building2 className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium">{company.name}</span>
          {!company.is_active && <Badge variant="secondary">Inactive</Badge>}
        </div>
        <p className="truncate text-sm text-muted-foreground">
          {company.legal_name ?? company.contact_email ?? '—'}
        </p>
      </div>

      <div className="hidden w-40 shrink-0 text-sm text-muted-foreground sm:block">
        {company.counts?.users ?? 0} users · {company.counts?.vehicles ?? 0} vehicles
      </div>

      <Badge variant="secondary" className="shrink-0 capitalize">
        {company.subscription_tier}
      </Badge>
    </Link>
  );
}

function CompaniesPage() {
  const [search, setSearch] = React.useState('');
  // Debounced so a query is not fired on every keystroke.
  const [query, setQuery] = React.useState('');

  React.useEffect(() => {
    const id = setTimeout(() => setQuery(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const { data, isLoading, isError } = useCompanies(query ? { search: query } : {});
  const companies = data ?? [];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Link
            href="/admin"
            className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden />
            Admin console
          </Link>
          <h1 className="text-2xl font-semibold">Companies</h1>
          <p className="text-sm text-muted-foreground">
            Every tenant on the platform, and the plan each one is on.
          </p>
        </div>

        <Button asChild>
          <Link href="/admin/companies/new">
            <Plus aria-hidden />
            New company
          </Link>
        </Button>
      </div>

      <div className="relative max-w-sm">
        <Search
          className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden
        />
        <Input
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search by name"
          className="pl-9"
          aria-label="Search companies"
        />
      </div>

      {isLoading && <Skeleton className="h-64 w-full" />}

      {isError && (
        <EmptyState
          icon={Building2}
          title="Could not load companies"
          description="The list could not be fetched. Reload the page to try again."
        />
      )}

      {!isLoading && !isError && companies.length === 0 && (
        <EmptyState
          icon={Building2}
          title={query ? 'No companies match that search' : 'No companies yet'}
          description={
            query
              ? 'Try a different name.'
              : 'A company is the tenant everything else belongs to — users, vehicles, drivers and devices.'
          }
        />
      )}

      {companies.length > 0 && (
        <Card>
          <CardContent className="p-0">
            {companies.map((company) => (
              <CompanyRow key={company.id} company={company} />
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

export default function Page() {
  return (
    <RequireRole roles={['super_admin', 'system_admin']}>
      <CompaniesPage />
    </RequireRole>
  );
}
