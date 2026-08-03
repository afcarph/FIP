import { describe, expect, it } from 'vitest';
import { z } from 'zod';

import { PASSWORD_MIN_LENGTH, passwordSchema, withPasswordConfirmation } from '@/lib/validation';

/** The one message a failing rule reports, or null when the value is accepted. */
function reject(schema: z.ZodTypeAny, value: unknown): string | null {
  const result = schema.safeParse(value);
  return result.success ? null : (result.error.issues[0]?.message ?? '');
}

describe('password policy', () => {
  it('accepts a password meeting every rule', () => {
    expect(reject(passwordSchema, 'Str0ng!Passw0rd#2026')).toBeNull();
  });

  it('mirrors the API minimum length', () => {
    // 11 characters, otherwise valid — one short of the server's floor.
    expect(PASSWORD_MIN_LENGTH).toBe(12);
    expect(reject(passwordSchema, 'Sh0rt!Pass')).toContain('at least 12');
  });

  it.each([
    ['no lowercase', 'STR0NG!PASSWORD#', 'lowercase'],
    ['no uppercase', 'str0ng!password#', 'uppercase'],
    ['no number', 'Strong!Password#', 'number'],
    ['no symbol', 'Str0ngPassw0rd2026', 'symbol'],
  ])('rejects a password with %s', (_label, value, expected) => {
    expect(reject(passwordSchema, value)).toContain(expected);
  });
});

describe('password confirmation', () => {
  const schema = withPasswordConfirmation({ email: z.string().email() });

  it('accepts a matching pair', () => {
    const result = schema.safeParse({
      email: 'ella@example.com',
      password: 'Str0ng!Passw0rd#2026',
      password_confirmation: 'Str0ng!Passw0rd#2026',
    });

    expect(result.success).toBe(true);
  });

  it('reports a mismatch against the confirmation field, not the password', () => {
    const result = schema.safeParse({
      email: 'ella@example.com',
      password: 'Str0ng!Passw0rd#2026',
      password_confirmation: 'Different!Passw0rd#2026',
    });

    expect(result.success).toBe(false);

    if (!result.success) {
      // The error has to land on the confirmation input, or the form shows it
      // under the wrong field.
      expect(result.error.issues[0]?.path).toEqual(['password_confirmation']);
      expect(result.error.issues[0]?.message).toContain('do not match');
    }
  });

  it('still applies the base shape it was given', () => {
    const result = schema.safeParse({
      email: 'not-an-email',
      password: 'Str0ng!Passw0rd#2026',
      password_confirmation: 'Str0ng!Passw0rd#2026',
    });

    expect(result.success).toBe(false);
  });
});
