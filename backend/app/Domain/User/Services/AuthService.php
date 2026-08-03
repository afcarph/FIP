<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\Models\LoginAttempt;
use App\Domain\User\Models\OauthAccount;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\User\Models\UserPreference;
use App\Domain\User\Repositories\UserRepository;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Authentication orchestration: password, OAuth, biometric and MFA.
 *
 * Failure handling is deliberately uniform — an unknown email, a wrong
 * password and a disabled account all produce the same message and take a
 * comparable amount of time, so the endpoint cannot be used to enumerate
 * accounts. Every outcome, success or failure, lands in `login_attempts`.
 */
final readonly class AuthService
{
    /**
     * A valid cost-12 bcrypt digest of a discarded random string, compared
     * against when the email is unknown so that "no such user" and "wrong
     * password" take indistinguishable time.
     */
    private const TIMING_EQUALISATION_HASH = '$2y$12$yhhopS8ixp0XECUTW8pQQ.6nyCHMh2.lRnCRblmQRS/TBQdgcV9iW';

    public function __construct(
        private UserRepository $users,
        private MfaService $mfa,
    ) {}

    /**
     * Password login.
     *
     * @return array{status: string, ...}
     */
    public function login(string $email, string $password, ?array $device = null): array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Burn comparable time to a real bcrypt verification. This must be a
            // genuine cost-12 hash (of a random string nobody holds): Laravel's
            // hasher rejects a malformed digest outright, which would both skip
            // the work and surface a 500 instead of the intended 401.
            Hash::check($password, self::TIMING_EQUALISATION_HASH);
            LoginAttempt::record($email, null, false, 'unknown_email');

            throw $this->invalidCredentials();
        }

        if ($user->isLocked()) {
            LoginAttempt::record($email, $user->getKey(), false, 'account_locked');

            throw new DomainException(
                'Too many failed attempts. Try again in a few minutes.',
                'account_locked',
                423,
                ['locked_until' => $user->locked_until?->toIso8601String()],
            );
        }

        if ($user->password === null || ! Hash::check($password, $user->password)) {
            $this->users->registerFailedLogin($user);
            LoginAttempt::record($email, $user->getKey(), false, 'bad_password');

            throw $this->invalidCredentials();
        }

        if (! $user->isActive()) {
            LoginAttempt::record($email, $user->getKey(), false, 'inactive_account');

            throw new DomainException('This account is not active. Contact support.', 'account_inactive', 403);
        }

        // MFA users get a short-lived challenge token, not an access token.
        if ($user->mfa_enabled) {
            LoginAttempt::record($email, $user->getKey(), true, 'mfa_required');

            return [
                'status' => 'mfa_required',
                'challenge_token' => $this->mfa->issueChallenge($user),
                'expires_in' => 300,
            ];
        }

        return $this->issueSession($user, $device);
    }

    /** Complete an MFA challenge and exchange it for a session. */
    public function verifyMfa(string $challengeToken, string $code, ?array $device = null): array
    {
        $user = $this->mfa->consumeChallenge($challengeToken);

        if (! $this->mfa->verify($user, $code)) {
            LoginAttempt::record($user->email, $user->getKey(), false, 'bad_mfa_code');

            throw new DomainException('That verification code is not valid.', 'invalid_mfa_code', 422);
        }

        return $this->issueSession($user, $device);
    }

    /** Register a new self-service account. */
    public function register(array $data): array
    {
        if ($this->users->existsBy('email', mb_strtolower($data['email']))) {
            throw new DomainException('An account with that email already exists.', 'email_taken', 409);
        }

        $user = DB::transaction(function () use ($data): User {
            /** @var User $user */
            $user = $this->users->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'home_city_id' => $data['home_city_id'] ?? null,
                'status' => 'active',
            ]);

            $user->assignRole(config('fip.roles.user'));

            UserPreference::create([
                'user_id' => $user->getKey(),
                'theme' => 'system',
                'preferred_fuel_type_id' => $data['preferred_fuel_type_id'] ?? null,
            ]);

            return $user;
        });

        Log::channel('security')->info('Account registered', ['user_id' => $user->getKey(), 'email' => $user->email]);

        return $this->issueSession($user, $data['device'] ?? null);
    }

    /**
     * Social login. An existing account with the same verified email is
     * linked rather than duplicated; a brand-new provider identity creates a
     * passwordless account.
     */
    public function loginWithProvider(string $provider, array $profile, ?array $device = null): array
    {
        $user = $this->users->findByProvider($provider, $profile['id']);

        if ($user === null && ! empty($profile['email'])) {
            $user = $this->users->findByEmail($profile['email']);
        }

        $user = DB::transaction(function () use ($user, $provider, $profile): User {
            if ($user === null) {
                /** @var User $user */
                $user = $this->users->create([
                    'first_name' => $profile['first_name'] ?? Str::before((string) ($profile['name'] ?? 'User'), ' '),
                    'last_name' => $profile['last_name'] ?? Str::after((string) ($profile['name'] ?? 'User'), ' '),
                    'email' => $profile['email'] ?? "{$provider}_{$profile['id']}@users.fip.ph",
                    'password' => null,
                    'status' => 'active',
                    'avatar_path' => $profile['avatar'] ?? null,
                ]);

                $user->forceFill(['email_verified_at' => now()])->save();
                $user->assignRole(config('fip.roles.user'));
                UserPreference::firstOrCreate(['user_id' => $user->getKey()], ['theme' => 'system']);
            }

            OauthAccount::updateOrCreate(
                ['provider' => $provider, 'provider_uid' => $profile['id']],
                ['user_id' => $user->getKey(), 'email' => $profile['email'] ?? null, 'raw_payload' => $profile],
            );

            return $user;
        });

        if (! $user->isActive()) {
            throw new DomainException('This account is not active. Contact support.', 'account_inactive', 403);
        }

        return $this->issueSession($user, $device);
    }

    /**
     * Biometric login. The device signs a server-issued nonce with the key
     * enrolled at setup; the platform only ever stores the public key, so a
     * database leak cannot forge a biometric login.
     */
    public function loginWithBiometric(string $deviceUuid, string $signedNonce, string $nonce): array
    {
        $device = UserDevice::query()
            ->where('device_uuid', $deviceUuid)
            ->where('is_trusted', true)
            ->whereNotNull('biometric_key')
            ->first();

        if ($device === null) {
            throw new DomainException('This device is not enrolled for biometric sign-in.', 'device_not_enrolled', 403);
        }

        $verified = openssl_verify(
            $nonce,
            base64_decode($signedNonce, true) ?: '',
            $device->biometric_key,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            LoginAttempt::record($device->user->email, $device->user_id, false, 'bad_biometric_signature');

            throw new DomainException('Biometric verification failed.', 'biometric_failed', 401);
        }

        $user = $device->user;

        if (! $user->isActive()) {
            throw new DomainException('This account is not active. Contact support.', 'account_inactive', 403);
        }

        return $this->issueSession($user, ['device_uuid' => $deviceUuid]);
    }

    public function refresh(string $token): array
    {
        $newToken = JWTAuth::setToken($token)->refresh();

        return [
            'status' => 'ok',
            'access_token' => $newToken,
            'token_type' => 'bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
        ];
    }

    public function logout(User $user, ?string $deviceUuid = null): void
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        if ($deviceUuid !== null) {
            UserDevice::where('user_id', $user->getKey())
                ->where('device_uuid', $deviceUuid)
                ->update(['fcm_token' => null]);
        }

        Log::channel('security')->info('User logged out', ['user_id' => $user->getKey()]);
    }

    // ------------------------------------------------------------ internals

    private function issueSession(User $user, ?array $device): array
    {
        $token = JWTAuth::fromUser($user);

        $this->users->registerSuccessfulLogin($user, request()?->ip());
        LoginAttempt::record($user->email, $user->getKey(), true);

        if ($device !== null && ! empty($device['device_uuid'])) {
            $this->rememberDevice($user, $device);
        }

        return [
            'status' => 'ok',
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
            'user' => $user->load('preferences', 'company'),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }

    private function rememberDevice(User $user, array $device): void
    {
        UserDevice::updateOrCreate(
            ['user_id' => $user->getKey(), 'device_uuid' => $device['device_uuid']],
            array_filter([
                'device_name' => $device['device_name'] ?? null,
                'platform' => $device['platform'] ?? 'web',
                'fcm_token' => $device['fcm_token'] ?? null,
                'last_seen_at' => now(),
            ], static fn ($v) => $v !== null),
        );
    }

    private function invalidCredentials(): DomainException
    {
        return new DomainException('Those credentials do not match our records.', 'invalid_credentials', 401);
    }
}
