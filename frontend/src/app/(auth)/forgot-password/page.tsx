'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { MailCheck } from 'lucide-react';
import Link from 'next/link';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { AuthShell } from '@/components/auth/auth-shell';
import { FormError } from '@/components/auth/form-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForgotPassword } from '@/hooks/use-auth';
import { ApiError } from '@/lib/api-client';

const schema = z.object({
  email: z.string().email('Enter a valid email address.'),
});

type FormValues = z.infer<typeof schema>;

export default function ForgotPasswordPage() {
  const forgot = useForgotPassword();

  const {
    register,
    handleSubmit,
    getValues,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const error = forgot.error instanceof ApiError ? forgot.error : null;

  if (forgot.isSuccess) {
    return (
      <AuthShell
        title="Check your inbox"
        description="If that address belongs to an account, a reset link is on its way."
        footer={
          <Link href="/login" className="font-medium text-primary hover:underline">
            Back to sign in
          </Link>
        }
      >
        <div className="flex items-start gap-3 rounded-lg bg-primary/10 p-4 text-sm">
          <MailCheck className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
          <div className="space-y-1">
            <p className="font-medium">Sent to {getValues('email')}</p>
            <p className="text-muted-foreground">
              The link expires in 60 minutes. If nothing arrives, check your spam folder — or
              request another one.
            </p>
          </div>
        </div>

        <Button variant="outline" className="mt-4 w-full" onClick={() => forgot.reset()}>
          Use a different address
        </Button>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      title="Reset your password"
      description="Enter your email address and we'll send you a link to choose a new one."
      footer={
        <>
          Remembered it?{' '}
          <Link href="/login" className="font-medium text-primary hover:underline">
            Back to sign in
          </Link>
        </>
      }
    >
      <form
        onSubmit={handleSubmit((values) => forgot.mutate(values))}
        className="space-y-4"
        noValidate
      >
        {error ? <FormError>{error.message}</FormError> : null}

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

        <Button type="submit" className="w-full" loading={forgot.isPending}>
          Send reset link
        </Button>
      </form>
    </AuthShell>
  );
}
