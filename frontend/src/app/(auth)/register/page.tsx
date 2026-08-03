'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import Link from 'next/link';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { AuthShell } from '@/components/auth/auth-shell';
import { FormError } from '@/components/auth/form-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useRegister } from '@/hooks/use-auth';
import { ApiError } from '@/lib/api-client';
import { PASSWORD_MIN_LENGTH, withPasswordConfirmation } from '@/lib/validation';

const schema = withPasswordConfirmation({
  first_name: z.string().min(1, 'Enter your first name.').max(80),
  last_name: z.string().min(1, 'Enter your last name.').max(80),
  email: z.string().email('Enter a valid email address.').max(180),
  // Optional, but must match the API's shape when supplied.
  phone: z
    .string()
    .regex(/^\+?[0-9\s\-()]{7,32}$/, 'Enter a valid phone number, or leave it blank.')
    .optional()
    .or(z.literal('')),
  accepts_terms: z.literal(true, {
    errorMap: () => ({ message: 'You must accept the terms to create an account.' }),
  }),
});

type FormValues = z.infer<typeof schema>;

export default function RegisterPage() {
  const registration = useRegister();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const error = registration.error instanceof ApiError ? registration.error : null;

  return (
    <AuthShell
      title="Create your account"
      description="Track fuel prices, log fill-ups and see what you actually spend."
      footer={
        <>
          Already have an account?{' '}
          <Link href="/login" className="font-medium text-primary hover:underline">
            Sign in
          </Link>
        </>
      }
    >
      <form
        onSubmit={handleSubmit((values) =>
          registration.mutate({ ...values, phone: values.phone || undefined }),
        )}
        className="space-y-4"
        noValidate
      >
        {error ? (
          <FormError>
            <p>{error.message}</p>
            {/* Uniqueness and the breach check are server-side only. */}
            {error.fieldErrors.email ? (
              <p className="mt-1 text-xs opacity-80">{error.fieldErrors.email}</p>
            ) : null}
            {error.fieldErrors.password ? (
              <p className="mt-1 text-xs opacity-80">{error.fieldErrors.password}</p>
            ) : null}
          </FormError>
        ) : null}

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-2">
            <Label htmlFor="first_name">First name</Label>
            <Input
              id="first_name"
              autoComplete="given-name"
              error={Boolean(errors.first_name)}
              aria-describedby={errors.first_name ? 'first-name-error' : undefined}
              {...register('first_name')}
            />
            {errors.first_name ? (
              <p id="first-name-error" className="text-xs text-destructive">
                {errors.first_name.message}
              </p>
            ) : null}
          </div>

          <div className="space-y-2">
            <Label htmlFor="last_name">Last name</Label>
            <Input
              id="last_name"
              autoComplete="family-name"
              error={Boolean(errors.last_name)}
              aria-describedby={errors.last_name ? 'last-name-error' : undefined}
              {...register('last_name')}
            />
            {errors.last_name ? (
              <p id="last-name-error" className="text-xs text-destructive">
                {errors.last_name.message}
              </p>
            ) : null}
          </div>
        </div>

        <div className="space-y-2">
          <Label htmlFor="email">Email</Label>
          <Input
            id="email"
            type="email"
            autoComplete="email"
            placeholder="you@example.com"
            error={Boolean(errors.email)}
            aria-describedby={errors.email ? 'email-error' : undefined}
            {...register('email')}
          />
          {errors.email ? (
            <p id="email-error" className="text-xs text-destructive">
              {errors.email.message}
            </p>
          ) : null}
        </div>

        <div className="space-y-2">
          <Label htmlFor="phone">
            Mobile number <span className="text-muted-foreground">(optional)</span>
          </Label>
          <Input
            id="phone"
            type="tel"
            autoComplete="tel"
            placeholder="+63 917 123 4567"
            error={Boolean(errors.phone)}
            aria-describedby={errors.phone ? 'phone-error' : undefined}
            {...register('phone')}
          />
          {errors.phone ? (
            <p id="phone-error" className="text-xs text-destructive">
              {errors.phone.message}
            </p>
          ) : null}
        </div>

        <div className="space-y-2">
          <Label htmlFor="password">Password</Label>
          <Input
            id="password"
            type="password"
            autoComplete="new-password"
            error={Boolean(errors.password)}
            aria-describedby={errors.password ? 'password-error' : 'password-hint'}
            {...register('password')}
          />
          {errors.password ? (
            <p id="password-error" className="text-xs text-destructive">
              {errors.password.message}
            </p>
          ) : (
            <p id="password-hint" className="text-xs text-muted-foreground">
              At least {PASSWORD_MIN_LENGTH} characters, with upper and lower case, a number and a
              symbol.
            </p>
          )}
        </div>

        <div className="space-y-2">
          <Label htmlFor="password_confirmation">Confirm password</Label>
          <Input
            id="password_confirmation"
            type="password"
            autoComplete="new-password"
            error={Boolean(errors.password_confirmation)}
            aria-describedby={errors.password_confirmation ? 'confirm-error' : undefined}
            {...register('password_confirmation')}
          />
          {errors.password_confirmation ? (
            <p id="confirm-error" className="text-xs text-destructive">
              {errors.password_confirmation.message}
            </p>
          ) : null}
        </div>

        <div className="space-y-2">
          <div className="flex items-start gap-2">
            <input
              id="accepts_terms"
              type="checkbox"
              className="mt-0.5 size-4 rounded border-input text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-describedby={errors.accepts_terms ? 'terms-error' : undefined}
              {...register('accepts_terms')}
            />
            <Label htmlFor="accepts_terms" className="text-sm font-normal leading-snug">
              I agree to the terms of service and the privacy policy.
            </Label>
          </div>
          {errors.accepts_terms ? (
            <p id="terms-error" className="text-xs text-destructive">
              {errors.accepts_terms.message}
            </p>
          ) : null}
        </div>

        <Button type="submit" className="w-full" loading={registration.isPending}>
          Create account
        </Button>
      </form>
    </AuthShell>
  );
}
