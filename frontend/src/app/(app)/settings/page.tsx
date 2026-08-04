'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { FormError } from '@/components/auth/form-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
  useChangePassword,
  useFuelTypes,
  useUpdatePreferences,
  useUpdateProfile,
} from '@/hooks/use-api';
import { useAuth } from '@/hooks/use-auth';
import { ApiError } from '@/lib/api-client';
import { passwordSchema } from '@/lib/validation';

const profileSchema = z.object({
  first_name: z.string().min(1, 'Enter your first name.').max(80),
  last_name: z.string().min(1, 'Enter your last name.').max(80),
  email: z.string().email('Enter a valid email address.').max(180),
  phone: z.string().max(32).optional(),
});

const passwordFormSchema = z
  .object({
    current_password: z.string().min(1, 'Enter your current password.'),
    password: passwordSchema,
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Those passwords do not match.',
    path: ['password_confirmation'],
  });

const preferencesSchema = z.object({
  theme: z.enum(['light', 'dark', 'system']),
  preferred_fuel_type_id: z.coerce.number().int().optional().or(z.literal('')),
  alert_radius_km: z.coerce
    .number()
    .min(0.5, 'Minimum is 0.5 km.')
    .max(50, 'Maximum is 50 km.'),
  notify_price_alerts: z.boolean(),
  notify_maintenance: z.boolean(),
  notify_ai_insights: z.boolean(),
});

function SectionResult({ error, saved }: { error: ApiError | null; saved: boolean }) {
  if (error) {
    return <FormError>{error.message}</FormError>;
  }

  if (saved) {
    return (
      <p role="status" className="text-sm text-muted-foreground">
        Saved.
      </p>
    );
  }

  return null;
}

export default function SettingsPage() {
  const { user, isLoading } = useAuth();
  const { data: fuelTypes } = useFuelTypes();

  const profile = useUpdateProfile();
  const preferences = useUpdatePreferences();
  const password = useChangePassword();

  const profileForm = useForm<z.input<typeof profileSchema>>({
    resolver: zodResolver(profileSchema),
    values: user
      ? {
          first_name: user.first_name,
          last_name: user.last_name,
          email: user.email,
          phone: user.phone ?? '',
        }
      : undefined,
  });

  const preferencesForm = useForm<z.input<typeof preferencesSchema>>({
    resolver: zodResolver(preferencesSchema),
    values: user?.preferences
      ? {
          theme: user.preferences.theme,
          preferred_fuel_type_id: user.preferences.preferred_fuel_type_id ?? '',
          alert_radius_km: user.preferences.alert_radius_km,
          notify_price_alerts: user.preferences.notify_price_alerts,
          notify_maintenance: user.preferences.notify_maintenance,
          notify_ai_insights: user.preferences.notify_ai_insights,
        }
      : undefined,
  });

  const passwordForm = useForm<z.input<typeof passwordFormSchema>>({
    resolver: zodResolver(passwordFormSchema),
  });

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-48" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  return (
    <div className="mx-auto w-full max-w-3xl space-y-6">
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
        <p className="text-sm text-muted-foreground">Your details, alerts and password</p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Your details</CardTitle>
        </CardHeader>
        <CardContent>
          <form
            className="space-y-5"
            onSubmit={profileForm.handleSubmit((values) =>
              profile.mutate({ ...values, phone: values.phone || null }),
            )}
          >
            <SectionResult
              error={profile.error instanceof ApiError ? profile.error : null}
              saved={profile.isSuccess}
            />

            <div className="grid gap-5 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="first_name">First name</Label>
                <Input id="first_name" {...profileForm.register('first_name')} />
                {profileForm.formState.errors.first_name ? (
                  <p className="text-sm text-destructive">
                    {profileForm.formState.errors.first_name.message}
                  </p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="last_name">Last name</Label>
                <Input id="last_name" {...profileForm.register('last_name')} />
                {profileForm.formState.errors.last_name ? (
                  <p className="text-sm text-destructive">
                    {profileForm.formState.errors.last_name.message}
                  </p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="email">Email</Label>
                <Input id="email" type="email" {...profileForm.register('email')} />
                {profileForm.formState.errors.email ? (
                  <p className="text-sm text-destructive">
                    {profileForm.formState.errors.email.message}
                  </p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="phone">
                  Mobile number <span className="text-muted-foreground">(optional)</span>
                </Label>
                <Input id="phone" type="tel" {...profileForm.register('phone')} />
              </div>
            </div>

            <div className="flex justify-end">
              <Button type="submit" disabled={profile.isPending}>
                {profile.isPending ? 'Saving…' : 'Save details'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Alerts</CardTitle>
        </CardHeader>
        <CardContent>
          <form
            className="space-y-5"
            onSubmit={preferencesForm.handleSubmit((values) =>
              preferences.mutate({
                ...values,
                preferred_fuel_type_id: values.preferred_fuel_type_id || null,
              }),
            )}
          >
            <SectionResult
              error={preferences.error instanceof ApiError ? preferences.error : null}
              saved={preferences.isSuccess}
            />

            <div className="grid gap-5 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="theme">Theme</Label>
                <select
                  id="theme"
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                  {...preferencesForm.register('theme')}
                >
                  <option value="system">Match my device</option>
                  <option value="light">Light</option>
                  <option value="dark">Dark</option>
                </select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="preferred_fuel_type_id">Usual fuel</Label>
                <select
                  id="preferred_fuel_type_id"
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                  {...preferencesForm.register('preferred_fuel_type_id')}
                >
                  <option value="">No preference</option>
                  {fuelTypes?.map((fuelType) => (
                    <option key={fuelType.id} value={fuelType.id}>
                      {fuelType.name}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="alert_radius_km">Alert radius (km)</Label>
                <Input
                  id="alert_radius_km"
                  type="number"
                  step="0.5"
                  min="0.5"
                  max="50"
                  {...preferencesForm.register('alert_radius_km')}
                />
                {preferencesForm.formState.errors.alert_radius_km ? (
                  <p className="text-sm text-destructive">
                    {preferencesForm.formState.errors.alert_radius_km.message}
                  </p>
                ) : null}
              </div>
            </div>

            <fieldset className="space-y-3">
              <legend className="text-sm font-medium">Tell me about</legend>
              {(
                [
                  ['notify_price_alerts', 'Price movements near me'],
                  ['notify_maintenance', 'Maintenance and renewals'],
                  ['notify_ai_insights', 'Insights and forecasts'],
                ] as const
              ).map(([name, label]) => (
                <label key={name} className="flex items-center gap-3 text-sm">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-input"
                    {...preferencesForm.register(name)}
                  />
                  {label}
                </label>
              ))}
            </fieldset>

            <div className="flex justify-end">
              <Button type="submit" disabled={preferences.isPending}>
                {preferences.isPending ? 'Saving…' : 'Save alerts'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Password</CardTitle>
        </CardHeader>
        <CardContent>
          <form
            className="space-y-5"
            onSubmit={passwordForm.handleSubmit(async (values) => {
              await password.mutateAsync(values);
              // Nothing here is worth keeping on screen once it has been sent.
              passwordForm.reset();
            })}
          >
            <SectionResult
              error={password.error instanceof ApiError ? password.error : null}
              saved={password.isSuccess}
            />

            <div className="space-y-2">
              <Label htmlFor="current_password">Current password</Label>
              <Input
                id="current_password"
                type="password"
                autoComplete="current-password"
                {...passwordForm.register('current_password')}
              />
              {passwordForm.formState.errors.current_password ? (
                <p className="text-sm text-destructive">
                  {passwordForm.formState.errors.current_password.message}
                </p>
              ) : null}
            </div>

            <div className="grid gap-5 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="new_password">New password</Label>
                <Input
                  id="new_password"
                  type="password"
                  autoComplete="new-password"
                  {...passwordForm.register('password')}
                />
                {passwordForm.formState.errors.password ? (
                  <p className="text-sm text-destructive">
                    {passwordForm.formState.errors.password.message}
                  </p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="password_confirmation">Confirm new password</Label>
                <Input
                  id="password_confirmation"
                  type="password"
                  autoComplete="new-password"
                  {...passwordForm.register('password_confirmation')}
                />
                {passwordForm.formState.errors.password_confirmation ? (
                  <p className="text-sm text-destructive">
                    {passwordForm.formState.errors.password_confirmation.message}
                  </p>
                ) : null}
              </div>
            </div>

            <div className="flex justify-end">
              <Button type="submit" disabled={password.isPending}>
                {password.isPending ? 'Updating…' : 'Update password'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
