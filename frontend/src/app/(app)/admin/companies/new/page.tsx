'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { FormError } from '@/components/auth/form-error';
import { RequireRole } from '@/components/auth/require-role';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCreateCompany, useSubscriptionTiers } from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';

/**
 * Mirrors StoreCompanyRequest, so the form rejects what the API would.
 *
 * The tier list is fetched rather than hardcoded. It used to be a literal here
 * and in the detail page, so a tier added in config never appeared and a
 * renamed one was offered until the server refused it with a 422.
 */
const schema = z.object({
  name: z.string().min(1, 'Enter the company name.').max(180, 'That name is too long.'),
  legal_name: z.string().max(180).optional(),
  tin: z.string().max(32).optional(),
  industry: z.string().max(80).optional(),
  contact_email: z.string().email('Enter a valid email address.').optional().or(z.literal('')),
  contact_phone: z.string().max(32).optional(),
  // Validated against the served list at submit time rather than a literal.
  subscription_tier: z.string().min(1, 'Choose a plan.'),
});

type FormValues = z.infer<typeof schema>;

function NewCompanyPage() {
  const router = useRouter();
  const creation = useCreateCompany();
  const { data: tierData, isLoading: tiersLoading } = useSubscriptionTiers();
  const tiers = tierData?.tiers ?? [];

  const {
    register,
    handleSubmit,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { subscription_tier: '' },
  });

  // Selected once the served list arrives. Deliberately not react-hook-form's
  // `values` prop, which resets the entire form whenever it changes — that
  // would wipe anything already typed the moment this query resolved.
  React.useEffect(() => {
    if (tierData?.default) setValue('subscription_tier', tierData.default);
  }, [tierData?.default, setValue]);

  const error = creation.error instanceof ApiError ? creation.error : null;

  return (
    <div className="mx-auto w-full max-w-2xl space-y-6">
      <Button asChild variant="ghost" size="sm" className="-ml-2">
        <Link href="/admin/companies">
          <ArrowLeft aria-hidden />
          Companies
        </Link>
      </Button>

      <Card>
        <CardHeader>
          <CardTitle>New company</CardTitle>
          <p className="text-sm text-muted-foreground">
            The name and a plan are enough to start. Everything else — users, vehicles, drivers —
            belongs to the company once it exists.
          </p>
        </CardHeader>

        <CardContent>
          <form
            className="space-y-5"
            onSubmit={handleSubmit(async (values) => {
              const company = await creation.mutateAsync({
                ...values,
                // Empty strings would fail the API's `email` rule, where the
                // field is genuinely optional.
                legal_name: values.legal_name || undefined,
                tin: values.tin || undefined,
                industry: values.industry || undefined,
                contact_email: values.contact_email || undefined,
                contact_phone: values.contact_phone || undefined,
              });

              router.push(`/admin/companies/${company.id}`);
            })}
          >
            {error ? <FormError>{error.message}</FormError> : null}

            <div className="space-y-2">
              <Label htmlFor="name">Company name</Label>
              <Input id="name" placeholder="Northwind Haulage" {...register('name')} />
              {errors.name ? (
                <p className="text-sm text-destructive">{errors.name.message}</p>
              ) : null}
            </div>

            <div className="grid gap-5 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="legal_name">Registered name</Label>
                <Input id="legal_name" {...register('legal_name')} />
              </div>

              <div className="space-y-2">
                <Label htmlFor="tin">TIN</Label>
                <Input id="tin" {...register('tin')} />
              </div>

              <div className="space-y-2">
                <Label htmlFor="contact_email">Contact email</Label>
                <Input id="contact_email" type="email" {...register('contact_email')} />
                {errors.contact_email ? (
                  <p className="text-sm text-destructive">{errors.contact_email.message}</p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="contact_phone">Contact phone</Label>
                <Input id="contact_phone" {...register('contact_phone')} />
              </div>

              <div className="space-y-2">
                <Label htmlFor="industry">Industry</Label>
                <Input id="industry" {...register('industry')} />
              </div>

              <div className="space-y-2">
                <Label htmlFor="subscription_tier">Plan</Label>
                <select
                  id="subscription_tier"
                  disabled={tiersLoading}
                  className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                  {...register('subscription_tier')}
                >
                  {tiers.map((tier) => (
                    <option key={tier.name} value={tier.name}>
                      {tier.label}
                    </option>
                  ))}
                </select>
                <p className="text-xs text-muted-foreground">
                  Decides how many vehicles, seats and devices the tenant may create.
                  {tierData?.is_provisional ? ' These limits are provisional.' : ''}
                </p>
              </div>
            </div>

            <div className="flex justify-end gap-3">
              <Button asChild variant="ghost" type="button">
                <Link href="/admin/companies">Cancel</Link>
              </Button>
              <Button type="submit" disabled={isSubmitting || creation.isPending}>
                {creation.isPending ? 'Creating…' : 'Create company'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

export default function Page() {
  return (
    <RequireRole roles={['super_admin', 'system_admin']}>
      <NewCompanyPage />
    </RequireRole>
  );
}
