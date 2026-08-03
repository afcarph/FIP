<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\Models\User;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP multi-factor authentication (RFC 6238).
 *
 * Secrets are encrypted at rest by the User model. Recovery codes are stored
 * as bcrypt hashes and are single-use. The interstitial challenge token lives
 * only in Redis for five minutes so a stolen challenge is worthless.
 */
final readonly class MfaService
{
    private const CHALLENGE_PREFIX = 'mfa:challenge:';

    private const CHALLENGE_TTL = 300;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private Google2FA $google2fa) {}

    /**
     * Begin enrolment: generate a secret and the provisioning URI the
     * authenticator app scans. Nothing is enabled until confirm() succeeds.
     *
     * @return array{secret: string, qr_uri: string}
     */
    public function beginEnrolment(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        Cache::put($this->enrolmentKey($user), $secret, now()->addMinutes(15));

        return [
            'secret' => $secret,
            'qr_uri' => $this->google2fa->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $secret,
            ),
        ];
    }

    /**
     * Confirm enrolment with the first valid code and hand back the one-time
     * recovery codes. These are shown exactly once.
     *
     * @return list<string>
     */
    public function confirmEnrolment(User $user, string $code): array
    {
        $secret = Cache::get($this->enrolmentKey($user));

        if ($secret === null) {
            throw new DomainException('Enrolment expired. Start again.', 'mfa_enrolment_expired', 410);
        }

        if (! $this->google2fa->verifyKey($secret, $code, (int) config('fip.security.mfa_window'))) {
            throw new DomainException('That verification code is not valid.', 'invalid_mfa_code', 422);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->setMfaSecret($secret);
        $user->setRecoveryCodes(array_map(static fn (string $c) => Hash::make($c), $recoveryCodes));
        $user->forceFill(['mfa_enabled' => true])->save();

        Cache::forget($this->enrolmentKey($user));

        return $recoveryCodes;
    }

    public function disable(User $user, string $code): void
    {
        if (! $this->verify($user, $code)) {
            throw new DomainException('That verification code is not valid.', 'invalid_mfa_code', 422);
        }

        $user->setMfaSecret(null);
        $user->forceFill(['mfa_enabled' => false, 'mfa_recovery_codes' => null])->save();
    }

    /** Verify a TOTP code, falling back to a single-use recovery code. */
    public function verify(User $user, string $code): bool
    {
        $secret = $user->mfaSecret();

        if ($secret === null) {
            return false;
        }

        if ($this->google2fa->verifyKey($secret, $code, (int) config('fip.security.mfa_window'))) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /** Short-lived opaque token handed to the client between the two steps. */
    public function issueChallenge(User $user): string
    {
        $token = Str::random(64);

        Cache::put(self::CHALLENGE_PREFIX.hash('sha256', $token), $user->getKey(), self::CHALLENGE_TTL);

        return $token;
    }

    public function consumeChallenge(string $token): User
    {
        $key = self::CHALLENGE_PREFIX.hash('sha256', $token);
        $userId = Cache::pull($key);

        if ($userId === null) {
            throw new DomainException('This sign-in attempt expired. Start again.', 'mfa_challenge_expired', 410);
        }

        return User::findOrFail($userId);
    }

    // ------------------------------------------------------------ internals

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->recoveryCodes();

        foreach ($hashes as $index => $hash) {
            if (! Hash::check($code, $hash)) {
                continue;
            }

            unset($hashes[$index]);
            $user->setRecoveryCodes($hashes);
            $user->save();

            return true;
        }

        return false;
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            static fn () => strtoupper(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    private function enrolmentKey(User $user): string
    {
        return "mfa:enrolment:{$user->getKey()}";
    }
}
