<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\Services\SubscriptionLimitService;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Giving a driver something to sign in with.
 *
 * A driver record can exist long before the person has a login — that is how a
 * fleet is entered — and until they have one they cannot open the app, so the
 * device step of onboarding is unreachable. Creating that login was previously
 * possible only through user administration, which a fleet manager cannot
 * reach: they could hire the driver, assign them a vehicle and read their
 * licence, but not hand them a way in.
 *
 * This is deliberately not a general user-creation path. It makes one shape of
 * account and refuses everything else: the `driver` role, in the driver's own
 * company, attached to that driver record. A caller cannot choose the role,
 * cannot name another company, and cannot reach a driver outside their own.
 *
 * On passwords. Mail does not leave this deployment yet, so "we have emailed
 * them an invitation" would be a lie. Instead a temporary password is
 * generated here — never chosen by the manager, who would pick something
 * weak and would then know their driver's credentials indefinitely — returned
 * exactly once for them to hand over, and stored only as a hash. When mail
 * works, this should become an invitation link and the temporary password
 * should stop existing; the shape of the endpoint does not have to change for
 * that.
 */
final class DriverAccountService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SubscriptionLimitService $limits,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{user: User, temporary_password: string}
     */
    public function createFor(Driver $driver, array $data): array
    {
        if ($driver->user_id !== null) {
            throw new DomainException(
                'That driver already has a login.',
                'driver_already_has_account',
                409,
            );
        }

        $email = mb_strtolower(trim((string) $data['email']));

        if ($this->users->existsBy('email', $email)) {
            throw new DomainException(
                'An account with that email already exists. If it belongs to this driver, link it instead of creating another.',
                'email_taken',
                409,
            );
        }

        // A login is a seat. Checked before anything is written so a company at
        // its limit is refused with the usual 402 rather than discovering it
        // halfway through.
        $this->limits->assertCompanyCanAdd($driver->company_id, SubscriptionLimitService::SEATS);

        $password = $this->temporaryPassword();

        $user = DB::transaction(function () use ($driver, $email, $data, $password): User {
            /** @var User $user */
            $user = $this->users->create([
                // The driver record already holds the person's name; asking for
                // it again invites two spellings of the same human.
                'first_name' => $driver->first_name,
                'last_name' => $driver->last_name,
                'email' => $email,
                'phone' => $data['phone'] ?? $driver->phone,
                'password' => $password,
                // Their company, taken from the driver, never from the request.
                'company_id' => $driver->company_id,
                'status' => 'active',
            ]);

            $user->assignRole('driver');

            UserPreference::create(['user_id' => $user->getKey(), 'theme' => 'system']);

            $driver->forceFill(['user_id' => $user->getKey()])->save();

            return $user;
        });

        Log::channel('security')->info('Driver login created', [
            'driver_id' => $driver->getKey(),
            'user_id' => $user->getKey(),
            'company_id' => $driver->company_id,
        ]);

        return ['user' => $user, 'temporary_password' => $password];
    }

    /**
     * Long and random rather than memorable.
     *
     * It is read once off a screen and typed once into a phone, so it does not
     * need to be pronounceable — and anything a person would find easy to
     * remember is the wrong trade for a credential that grants access to a
     * fleet's movements. Character classes are forced rather than hoped for,
     * because the same policy that guards registration applies here.
     */
    private function temporaryPassword(): string
    {
        return Str::password(16, letters: true, numbers: true, symbols: true, spaces: false);
    }
}
