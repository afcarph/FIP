'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { CheckCircle2, LinkIcon } from 'lucide-react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { AuthShell } from '@/components/auth/auth-shell';
import { FormError } from '@/components/auth/form-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useResetPassword } from '@/hooks/use-auth';
import { ApiError } from '@/lib/api-client';
import { PASSWORD_MIN_LENGTH, withPasswordConfirmation } from '@/lib/validation';

const schema = withPasswordConfirmation({});

type FormValues = z.infer<typeof schema>;

const backToRequest = (
  <Link href="/forgot-password" className="font-medium text-primary hover:underline">
    Request a new link
  </Link>
);

export function ResetPasswordForm() {
  const params = useSearchParams();
  const reset = useResetPassword();

  // Both arrive in the emailed link; the API verifies the pair.
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const error = reset.error instanceof ApiError ? reset.error : null;

  // A link that arrived without its credentials cannot be completed, and asking
  // for a password first would only waste the effort.
  if (!token || !email) {
    return (
      <AuthShell title="That link is incomplete" footer={backToRequest}>
        <div className="flex items-start gap-3 rounded-lg bg-destructive/10 p-4 text-sm text-destructive">
          <LinkIcon className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
          <p>
            The reset link is missing its token or address. Email clients sometimes truncate long
            links — request a fresh one and open it directly.
          </p>
        </div>
      </AuthShell>
    );
  }

  if (reset.isSuccess) {
    return (
      <AuthShell
        title="Password updated"
        footer={
          <Link href="/login" className="font-medium text-primary hover:underline">
            Go to sign in
          </Link>
        }
      >
        <div className="flex items-start gap-3 rounded-lg bg-primary/10 p-4 text-sm">
          <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
          <p>
            Your password has been changed. Any other devices signed in as {email} will need it
            again.
          </p>
        </div>

        <Button asChild className="mt-4 w-full">
          <Link href="/login">Sign in</Link>
        </Button>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      title="Choose a new password"
      description={`Setting a new password for ${email}.`}
      footer={backToRequest}
    >
      <form
        onSubmit={handleSubmit((values) =>
          reset.mutate({
            token,
            email,
            password: values.password,
            password_confirmation: values.password_confirmation,
          }),
        )}
        className="space-y-4"
        noValidate
      >
        {error ? (
          <FormError>
            <p>{error.message}</p>
            {error.code === 'reset_failed' ? (
              <p className="mt-1 text-xs opacity-80">
                Reset links expire after 60 minutes and can only be used once.
              </p>
            ) : null}
            {/* The breach check runs server-side only, so its message arrives here. */}
            {error.fieldErrors.password ? (
              <p className="mt-1 text-xs opacity-80">{error.fieldErrors.password}</p>
            ) : null}
          </FormError>
        ) : null}

        <div className="space-y-2">
          <Label htmlFor="password">New password</Label>
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
          <Label htmlFor="password_confirmation">Confirm new password</Label>
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

        <Button type="submit" className="w-full" loading={reset.isPending}>
          Update password
        </Button>
      </form>
    </AuthShell>
  );
}
