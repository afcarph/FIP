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
import { useLogin } from '@/hooks/use-auth';
import { ApiError } from '@/lib/api-client';

const schema = z.object({
  email: z.string().email('Enter a valid email address.'),
  password: z.string().min(1, 'Enter your password.'),
});

type FormValues = z.infer<typeof schema>;

export default function LoginPage() {
  const login = useLogin();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const error = login.error instanceof ApiError ? login.error : null;

  return (
    <AuthShell
      title="Sign in"
      description="Welcome back — enter your details to continue."
      footer={
        <>
          New here?{' '}
          <Link href="/register" className="font-medium text-primary hover:underline">
            Create an account
          </Link>
        </>
      }
    >
      <form
        onSubmit={handleSubmit((values) => login.mutate(values))}
        className="space-y-4"
        noValidate
      >
        {error ? (
          <FormError>
            <p>{error.message}</p>
            {error.code === 'account_locked' ? (
              <p className="mt-1 text-xs opacity-80">
                Your account is temporarily locked after several failed attempts.
              </p>
            ) : null}
          </FormError>
        ) : null}

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
          <div className="flex items-center justify-between">
            <Label htmlFor="password">Password</Label>
            <Link href="/forgot-password" className="text-xs text-primary hover:underline">
              Forgot password?
            </Link>
          </div>
          <Input
            id="password"
            type="password"
            autoComplete="current-password"
            error={Boolean(errors.password)}
            aria-describedby={errors.password ? 'password-error' : undefined}
            {...register('password')}
          />
          {errors.password ? (
            <p id="password-error" className="text-xs text-destructive">
              {errors.password.message}
            </p>
          ) : null}
        </div>

        <Button type="submit" className="w-full" loading={login.isPending}>
          Sign in
        </Button>

        <div className="relative py-1">
          <div className="absolute inset-0 flex items-center">
            <span className="w-full border-t" />
          </div>
          <div className="relative flex justify-center text-xs">
            <span className="bg-card px-2 text-muted-foreground">or continue with</span>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Button variant="outline" type="button" asChild>
            <a href={`${process.env.NEXT_PUBLIC_API_URL}/auth/google/redirect`}>Google</a>
          </Button>
          <Button variant="outline" type="button" asChild>
            <a href={`${process.env.NEXT_PUBLIC_API_URL}/auth/apple/redirect`}>Apple</a>
          </Button>
        </div>
      </form>
    </AuthShell>
  );
}
