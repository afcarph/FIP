'use client';

import { ShieldOff } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useAuth } from '@/hooks/use-auth';

/**
 * Gates a page on a permission rather than a role.
 *
 * RequireRole covers the common case, but not this one: a company manager is a
 * fleet role in every other respect and deliberately does not hold
 * `devices.location.history`. Listing roles here would either hand them a page
 * the API refuses, or hard-code a copy of the permission table that drifts the
 * first time a role is granted the permission.
 *
 * Presentational, exactly like RequireRole. The API refuses regardless; this
 * is so the app says what happened instead of rendering an empty page.
 */
export function RequirePermission({
  permission,
  reason,
  children,
}: {
  permission: string;
  reason: string;
  children: React.ReactNode;
}) {
  const { can, isLoading } = useAuth();

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (!can(permission)) {
    return (
      <EmptyState
        icon={ShieldOff}
        title="You do not have access to this page"
        description={reason}
        action={
          <Button asChild size="sm">
            <Link href="/fleet">Back to fleet</Link>
          </Button>
        }
      />
    );
  }

  return <>{children}</>;
}
