'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import * as React from 'react';

import { api, ApiError, deviceUuid, tokenStore } from '@/lib/api-client';
import type { MfaChallenge, Role, Session, User } from '@/types/api';

interface AuthContextValue {
  user: User | null;
  roles: Role[];
  permissions: string[];
  isLoading: boolean;
  isAuthenticated: boolean;
  can: (permission: string) => boolean;
  hasRole: (...roles: Role[]) => boolean;
  isAdmin: boolean;
  logout: () => Promise<void>;
}

const AuthContext = React.createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [hydrated, setHydrated] = React.useState(false);

  // localStorage is unavailable during SSR, so the first render must not try
  // to read it — otherwise the server and client markup disagree.
  React.useEffect(() => setHydrated(true), []);

  const { data, isLoading } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      const response = await api.get<{ user: User; roles: Role[]; permissions: string[] }>('/auth/me');
      return response.data;
    },
    enabled: hydrated && Boolean(tokenStore.get()),
    retry: (failureCount, error) =>
      // A 401 means the session is genuinely gone; retrying only delays the
      // redirect to the login page.
      !(error instanceof ApiError && error.isAuth) && failureCount < 2,
    staleTime: 5 * 60 * 1000,
  });

  const logout = React.useCallback(async () => {
    try {
      await api.post('/auth/logout', { device_uuid: deviceUuid() });
    } catch {
      // A failed logout call should still clear the local session.
    } finally {
      tokenStore.clear();
      queryClient.clear();
      router.push('/login');
    }
  }, [queryClient, router]);

  const value = React.useMemo<AuthContextValue>(() => {
    const roles = data?.roles ?? [];
    const permissions = data?.permissions ?? [];

    return {
      user: data?.user ?? null,
      roles,
      permissions,
      isLoading: !hydrated || isLoading,
      isAuthenticated: Boolean(data?.user),
      can: (permission) =>
        roles.includes('super_admin') || permissions.includes(permission),
      hasRole: (...candidates) => candidates.some((role) => roles.includes(role)),
      isAdmin: roles.includes('super_admin') || roles.includes('system_admin'),
      logout,
    };
  }, [data, hydrated, isLoading, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = React.useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used inside <AuthProvider>.');
  }

  return context;
}

/** Sign-in mutation, handling both the direct and the MFA-challenge paths. */
export function useLogin() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (credentials: { email: string; password: string }) => {
      const response = await api.post<Session | MfaChallenge>(
        '/auth/login',
        {
          ...credentials,
          device: {
            device_uuid: deviceUuid(),
            device_name: typeof navigator !== 'undefined' ? navigator.userAgent.slice(0, 120) : 'Web',
            platform: 'web',
          },
        },
        { skipAuth: true },
      );

      return response.data;
    },
    onSuccess: (data) => {
      if ('status' in data && data.status === 'mfa_required') {
        // Hand the challenge to the MFA step; no session exists yet.
        router.push(`/login/mfa?challenge=${encodeURIComponent(data.challenge_token)}`);
        return;
      }

      tokenStore.set((data as Session).access_token);
      queryClient.setQueryData(['auth', 'me'], {
        user: (data as Session).user,
        roles: (data as Session).roles,
        permissions: (data as Session).permissions,
      });
      router.push('/dashboard');
    },
  });
}

export function useRegister() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Record<string, unknown>) => {
      const response = await api.post<Session>(
        '/auth/register',
        { ...payload, device: { device_uuid: deviceUuid(), platform: 'web' } },
        { skipAuth: true },
      );

      return response.data;
    },
    onSuccess: (session) => {
      tokenStore.set(session.access_token);
      queryClient.setQueryData(['auth', 'me'], {
        user: session.user,
        roles: session.roles,
        permissions: session.permissions,
      });
      router.push('/dashboard');
    },
  });
}
