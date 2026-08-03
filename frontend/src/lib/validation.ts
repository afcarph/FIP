import { z } from 'zod';

/**
 * Mirrors the API's password policy (config/fip.php → security.password_min_length
 * plus Rules\Password::mixedCase()->numbers()->symbols()).
 *
 * Kept in one place so registration and password reset cannot drift apart, and
 * so a rule change here is a single edit. The server remains the authority: it
 * additionally checks the password against known-breach corpora, which cannot
 * be done in the browser, so a client-valid password may still be rejected with
 * a 422. Callers must surface that.
 */
export const PASSWORD_MIN_LENGTH = 12;

export const passwordSchema = z
  .string()
  .min(PASSWORD_MIN_LENGTH, `Use at least ${PASSWORD_MIN_LENGTH} characters.`)
  .regex(/[a-z]/, 'Include a lowercase letter.')
  .regex(/[A-Z]/, 'Include an uppercase letter.')
  .regex(/[0-9]/, 'Include a number.')
  .regex(/[^A-Za-z0-9]/, 'Include a symbol.');

/** Adds the confirmation field and the cross-field match check. */
export function withPasswordConfirmation<T extends z.ZodRawShape>(shape: T) {
  return z
    .object({ ...shape, password: passwordSchema, password_confirmation: z.string() })
    .refine((values) => values.password === values.password_confirmation, {
      message: 'The two passwords do not match.',
      path: ['password_confirmation'],
    });
}
