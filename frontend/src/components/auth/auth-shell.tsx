import { Fuel } from 'lucide-react';
import * as React from 'react';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * The centred, branded frame shared by every unauthenticated page. Extracted so
 * sign-in, registration and the two password-reset steps cannot drift apart
 * visually as they are edited independently.
 */
export function AuthShell({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description?: React.ReactNode;
  children: React.ReactNode;
  footer?: React.ReactNode;
}) {
  return (
    <main
      id="main"
      className="flex min-h-screen items-center justify-center bg-gradient-to-br from-background via-background to-primary/5 p-4"
    >
      <div className="w-full max-w-md">
        <div className="mb-8 flex flex-col items-center">
          <div className="mb-3 flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
            <Fuel className="size-6" aria-hidden="true" />
          </div>
          <h1 className="text-xl font-semibold">Fuel Intelligence Platform</h1>
        </div>

        <Card glass>
          <CardHeader>
            <CardTitle>{title}</CardTitle>
            {description ? <CardDescription>{description}</CardDescription> : null}
          </CardHeader>

          <CardContent>{children}</CardContent>
        </Card>

        {footer ? (
          <div className="mt-6 text-center text-sm text-muted-foreground">{footer}</div>
        ) : null}
      </div>
    </main>
  );
}
