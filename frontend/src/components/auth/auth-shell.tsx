import Image from 'next/image';
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
          {/* The real mark, not a generic pump glyph on a tile. Same asset as
              the header and the favicon, so the first screen a user sees is
              the brand rather than a placeholder. */}
          {/* The full lockup at its own aspect ratio — 824x1169. Forcing it
              into a square box squashes it, and the wordmark below already
              carries the name, so the heading beside it is redundant. */}
          <Image
            src="/fip-logo.png"
            alt="Fuel Intelligence Platform"
            width={824}
            height={1169}
            priority
            className="mb-2 h-32 w-auto"
          />
          <h1 className="sr-only">Fuel Intelligence Platform</h1>
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
