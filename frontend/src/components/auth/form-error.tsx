import { AlertCircle } from 'lucide-react';
import * as React from 'react';

/** Inline error banner used by the unauthenticated forms. */
export function FormError({ children }: { children: React.ReactNode }) {
  return (
    <div
      role="alert"
      className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
    >
      <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      <div>{children}</div>
    </div>
  );
}
