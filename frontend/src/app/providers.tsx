'use client';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { ThemeProvider } from 'next-themes';
import * as React from 'react';
import { Toaster } from 'sonner';

import { AuthProvider } from '@/hooks/use-auth';
import { ApiError } from '@/lib/api-client';

export function Providers({ children }: { children: React.ReactNode }) {
  // Created inside the component so each SSR pass gets its own cache and one
  // user's data can never leak into another's render.
  const [queryClient] = React.useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 60_000,
            gcTime: 5 * 60_000,
            refetchOnWindowFocus: false,
            retry: (failureCount, error) => {
              // 4xx responses are the caller's fault; retrying wastes time
              // and, on a rate limit, makes the situation worse.
              if (error instanceof ApiError && error.status < 500) return false;
              return failureCount < 2;
            },
          },
          mutations: { retry: false },
        },
      }),
  );

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
        <AuthProvider>
          {children}
          <Toaster
            position="top-right"
            richColors
            closeButton
            toastOptions={{ classNames: { toast: 'glass shadow-glass' } }}
          />
        </AuthProvider>
      </ThemeProvider>

      {process.env.NODE_ENV === 'development' ? (
        <ReactQueryDevtools initialIsOpen={false} />
      ) : null}
    </QueryClientProvider>
  );
}
