<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\Models\AuditLog;
use App\Domain\User\Models\Setting;

/**
 * Read and write persisted platform settings.
 *
 * The `settings` table and its model have existed since the first migration
 * and nothing has ever read from them — there was a store but no way in. This
 * is that way in, written generically rather than for the one setting that
 * needed it, because the alternative is a second settings mechanism the next
 * time somebody needs a configurable value.
 *
 * Every write is audited with its previous value. A setting that changes what
 * the platform deletes is exactly the kind of change somebody will later need
 * to account for.
 */
final class SettingsService
{
    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $setting = Setting::query()->where('group', $group)->where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        // The column is JSON and the model casts it to array, so a scalar was
        // stored wrapped. Unwrap it rather than making every caller know that.
        $value = $setting->value;

        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    /**
     * Whether an administrator has ever set this explicitly.
     *
     * The distinction matters: a setting falling back to a default is not the
     * same as one somebody chose, even when the two happen to agree.
     */
    public function isConfigured(string $group, string $key): bool
    {
        return Setting::query()->where('group', $group)->where('key', $key)->exists();
    }

    public function set(string $group, string $key, mixed $value, ?int $userId = null, bool $isPublic = false): Setting
    {
        $existing = Setting::query()->where('group', $group)->where('key', $key)->first();
        $previous = $existing === null ? null : $this->get($group, $key);

        $setting = Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => ['value' => $value], 'is_public' => $isPublic, 'updated_by' => $userId],
        );

        $this->audit($setting, $previous, $value);

        return $setting;
    }

    private function audit(Setting $setting, mixed $previous, mixed $current): void
    {
        $request = request();

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'setting.updated',
            'auditable_type' => Setting::class,
            'auditable_id' => $setting->getKey(),
            // The setting's identity, its old value and its new one. Nothing
            // about the administrator beyond the id already on the row.
            'old_values' => ['value' => $previous],
            'new_values' => [
                'group' => $setting->group,
                'key' => $setting->key,
                'value' => $current,
            ],
            'url' => $request->fullUrl(),
            'ip_address' => $request->ip() ? inet_pton($request->ip()) : null,
            'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }
}
