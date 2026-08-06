'use client';

import { ShieldOff } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useAuth } from '@/hooks/use-auth';
import type { Role } from '@/types/api';

/**
 * Gates a page on role. The sidebar already hides links a user cannot open, but
 * hiding a link is not the same as guarding a route: typing /admin rendered the
 * whole executive dashboard to anyone signed in. No data leaked — the API
 * answers 403 — so every figure read as an em dash, which looks like a broken
 * page rather than a closed door.
 *
 * The check is presentational. The API is the authority and refuses regardless;
 * this exists so the app says what happened instead of showing empty cards.
 */
export function RequireRole({
  roles,
  children,
}: {
  roles: Role[];
  children: React.ReactNode;
}) {
  const { hasRole, isLoading } = useAuth();

  // Waiting matters: rendering the refusal before roles arrive would flash
  // "no access" at a user who has it.
  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (!hasRole(...roles)) {
    return (
      <EmptyState
        icon={ShieldOff}
        title="You do not have access to this page"
        description="It is limited to fleet and administrator accounts. If you think you should be able to open it, ask whoever set up your account."
        action={
          <Button asChild size="sm">
            <Link href="/dashboard">Back to dashboard</Link>
          </Button>
        }
      />
    );
  }

  return <>{children}</>;
}
