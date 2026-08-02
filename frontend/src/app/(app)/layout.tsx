'use client';

import { useRouter } from 'next/navigation';
import * as React from 'react';

import { AppShell } from '@/components/layout/app-shell';
import { useAuth } from '@/hooks/use-auth';
import { tokenStore } from '@/lib/api-client';

/**
 * Client-side guard for the authenticated area.
 *
 * This is convenience, not security — every endpoint is independently
 * authorised server side. Its job is to avoid flashing a dashboard skeleton at
 * someone who is not signed in.
 */
export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { isAuthenticated, isLoading } = useAuth();

  React.useEffect(() => {
    if (!isLoading && !isAuthenticated && !tokenStore.get()) {
      router.replace('/login');
    }
  }, [isAuthenticated, isLoading, router]);

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="size-8 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        <span className="sr-only">Loading</span>
      </div>
    );
  }

  return <AppShell>{children}</AppShell>;
}
