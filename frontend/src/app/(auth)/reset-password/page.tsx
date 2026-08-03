import { Suspense } from 'react';

import { AuthShell } from '@/components/auth/auth-shell';
import { Skeleton } from '@/components/ui/skeleton';

import { ResetPasswordForm } from './reset-password-form';

export const metadata = {
  title: 'Reset password',
};

/**
 * The form reads the token and address from the query string, and
 * useSearchParams() opts a component out of prerendering unless it sits behind
 * a Suspense boundary. Keeping the boundary here lets the page stay static.
 */
export default function ResetPasswordPage() {
  return (
    <Suspense
      fallback={
        <AuthShell title="Choose a new password">
          <div className="space-y-4">
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
        </AuthShell>
      }
    >
      <ResetPasswordForm />
    </Suspense>
  );
}
