<?php

declare(strict_types=1);

namespace App\Domain\User\Repositories;

use App\Domain\User\Models\User;
use App\Support\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Model;

/** @extends BaseRepository<User> */
class UserRepository extends BaseRepository
{
    protected array $filterable = ['company_id', 'status', 'home_city_id'];

    protected array $sortable = ['id', 'first_name', 'last_name', 'email', 'created_at', 'last_login_at'];

    protected array $searchable = ['first_name', 'last_name', 'email', 'phone'];

    protected function model(): string
    {
        return User::class;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->query()->where('email', mb_strtolower(trim($email)))->first();
    }

    public function findByProvider(string $provider, string $providerUid): ?User
    {
        return $this->query()
            ->whereHas('oauthAccounts', fn ($q) => $q->where('provider', $provider)->where('provider_uid', $providerUid))
            ->first();
    }

    public function findByDevice(string $deviceUuid): ?User
    {
        return $this->query()
            ->whereHas('devices', fn ($q) => $q->where('device_uuid', $deviceUuid)->where('is_trusted', true))
            ->first();
    }

    public function registerFailedLogin(User $user): void
    {
        $max = (int) config('fip.security.max_failed_logins');
        $attempts = $user->failed_login_attempts + 1;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= $max
                ? now()->addMinutes((int) config('fip.security.lockout_minutes'))
                : $user->locked_until,
        ])->saveQuietly();
    }

    public function registerSuccessfulLogin(User $user, ?string $ip): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip ? inet_pton($ip) : null,
        ])->saveQuietly();
    }

    /** Platform-wide growth series for the executive dashboard. */
    public function growthByMonth(int $months = 12): array
    {
        return $this->query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS period, COUNT(*) AS total")
            ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => ['period' => $row->period, 'total' => (int) $row->total])
            ->all();
    }

    public function create(array $attributes): Model
    {
        $attributes['email'] = mb_strtolower(trim($attributes['email']));

        return parent::create($attributes);
    }
}
