'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft, Eye, EyeOff, Plus, UserRound, Users } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { FormError } from '@/components/auth/form-error';
import { RequireRole } from '@/components/auth/require-role';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminUsers, useCreateUser } from '@/hooks/use-api';
import { useAuth } from '@/hooks/use-auth';
import { ApiError } from '@/lib/api-client';
import { formatRelative } from '@/lib/utils';
import type { AdminUser } from '@/types/api';

/**
 * Roles a tenant administrator may hand out.
 *
 * super_admin and system_admin are absent deliberately. The API strips them
 * from anyone who is not a super administrator, so offering them here would
 * present a choice the server quietly refuses — worse than not offering it.
 */
const TENANT_ROLES = [
  { value: 'driver', label: 'Driver' },
  { value: 'fleet_manager', label: 'Fleet manager' },
  { value: 'company_manager', label: 'Company manager' },
  { value: 'viewer', label: 'Viewer' },
] as const;

/** Mirrors the API's rule, so the form refuses what the server would. */
const schema = z.object({
  first_name: z.string().min(1, 'Enter a first name.').max(80),
  last_name: z.string().min(1, 'Enter a last name.').max(80),
  email: z.string().email('Enter a valid email address.').max(180),
  phone: z.string().max(32).optional(),
  password: z
    .string()
    .min(12, 'Use at least 12 characters.')
    .regex(/[a-z]/, 'Include a lower-case letter.')
    .regex(/[A-Z]/, 'Include an upper-case letter.')
    .regex(/[0-9]/, 'Include a number.')
    .regex(/[^A-Za-z0-9]/, 'Include a symbol.'),
  role: z.enum(TENANT_ROLES.map((r) => r.value) as [string, ...string[]]),
});

type FormValues = z.infer<typeof schema>;

function AddUserForm({ onDone }: { onDone: () => void }) {
  const creation = useCreateUser();
  const [showPassword, setShowPassword] = React.useState(false);

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { role: 'driver' } });

  const error = creation.error instanceof ApiError ? creation.error : null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Add a user</CardTitle>
        <p className="text-sm text-muted-foreground">
          They join your company and can sign in immediately. Set a password you can pass on — there
          is no invitation email yet.
        </p>
      </CardHeader>
      <CardContent>
        <form
          className="space-y-5"
          onSubmit={handleSubmit(async ({ role, ...values }) => {
            await creation.mutateAsync({
              ...values,
              phone: values.phone || undefined,
              // The company is inferred from the caller. Naming another is
              // refused by the API, so the form never offers the field.
              roles: [role],
            });

            reset();
            onDone();
          })}
        >
          {error ? <FormError>{error.message}</FormError> : null}

          <div className="grid gap-5 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="first_name">First name</Label>
              <Input id="first_name" {...register('first_name')} />
              {errors.first_name ? (
                <p className="text-sm text-destructive">{errors.first_name.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="last_name">Last name</Label>
              <Input id="last_name" {...register('last_name')} />
              {errors.last_name ? (
                <p className="text-sm text-destructive">{errors.last_name.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="email">Email</Label>
              <Input id="email" type="email" {...register('email')} />
              {errors.email ? (
                <p className="text-sm text-destructive">{errors.email.message}</p>
              ) : null}
            </div>

            <div className="space-y-2">
              <Label htmlFor="phone">Phone</Label>
              <Input id="phone" {...register('phone')} />
            </div>

            <div className="space-y-2">
              <Label htmlFor="password">Password</Label>
              <div className="relative">
                <Input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="new-password"
                  className="pr-10"
                  {...register('password')}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((shown) => !shown)}
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  aria-pressed={showPassword}
                  className="absolute right-0 top-0 flex h-full w-10 items-center justify-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  {showPassword ? (
                    <EyeOff className="h-4 w-4" aria-hidden />
                  ) : (
                    <Eye className="h-4 w-4" aria-hidden />
                  )}
                </button>
              </div>
              {errors.password ? (
                <p className="text-sm text-destructive">{errors.password.message}</p>
              ) : (
                <p className="text-xs text-muted-foreground">
                  12 characters or more, with mixed case, a number and a symbol.
                </p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="role">Role</Label>
              <select
                id="role"
                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                {...register('role')}
              >
                {TENANT_ROLES.map((role) => (
                  <option key={role.value} value={role.value}>
                    {role.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex justify-end gap-3">
            <Button variant="ghost" type="button" onClick={onDone}>
              Cancel
            </Button>
            <Button type="submit" disabled={creation.isPending}>
              {creation.isPending ? 'Adding…' : 'Add user'}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function UserRow({ user, isYou }: { user: AdminUser; isYou: boolean }) {
  return (
    <div className="flex items-center gap-4 border-b border-border px-4 py-3 last:border-0">
      <UserRound className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium">{user.full_name}</span>
          {isYou ? <Badge variant="secondary">You</Badge> : null}
          {user.status !== 'active' ? (
            <Badge variant="destructive" className="capitalize">
              {user.status}
            </Badge>
          ) : null}
        </div>
        <p className="truncate text-sm text-muted-foreground">{user.email}</p>
      </div>

      <div className="hidden w-40 shrink-0 text-sm text-muted-foreground sm:block">
        {user.company?.name ?? 'No company'}
      </div>

      <div className="w-36 shrink-0 text-right">
        <div className="flex flex-wrap justify-end gap-1">
          {user.roles.map((role) => (
            <Badge key={role} variant="secondary" className="capitalize">
              {role.replace(/_/g, ' ')}
            </Badge>
          ))}
        </div>
        <p className="mt-1 text-xs text-muted-foreground">
          {user.last_login_at ? formatRelative(user.last_login_at) : 'Never signed in'}
        </p>
      </div>
    </div>
  );
}

function UsersPage() {
  const [adding, setAdding] = React.useState(false);
  const { user: me } = useAuth();
  const { data, isLoading, isError } = useAdminUsers();
  const users = data ?? [];

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
          <h1 className="text-2xl font-semibold">Users</h1>
          <p className="text-sm text-muted-foreground">
            The people who can sign in to your company.
          </p>
        </div>

        {!adding && (
          <Button onClick={() => setAdding(true)}>
            <Plus aria-hidden />
            Add user
          </Button>
        )}
      </div>

      {adding && <AddUserForm onDone={() => setAdding(false)} />}

      {isLoading && <Skeleton className="h-64 w-full" />}

      {isError && (
        <EmptyState
          icon={Users}
          title="Could not load users"
          description="The list could not be fetched. Reload the page to try again."
        />
      )}

      {!isLoading && !isError && users.length === 0 && !adding && (
        <EmptyState
          icon={Users}
          title="No users yet"
          description="Add someone so they can sign in and start using the fleet."
        />
      )}

      {users.length > 0 && (
        <Card>
          <CardContent className="p-0">
            {users.map((user) => (
              <UserRow key={user.id} user={user} isYou={user.id === me?.id} />
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

export default function Page() {
  // company_manager is included deliberately: it holds users.view and
  // users.create, and this page exists so a tenant can onboard its own people
  // without anyone reaching for the API.
  return (
    <RequireRole roles={['company_manager', 'super_admin', 'system_admin']}>
      <UsersPage />
    </RequireRole>
  );
}
