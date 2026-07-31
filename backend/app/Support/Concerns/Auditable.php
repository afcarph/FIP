<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Domain\User\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records an immutable audit trail for every create / update / delete on the
 * model. Attributes listed in `$auditExclude` (secrets, tokens, hashes) are
 * redacted before the row is written.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->writeAudit('created', [], $model->auditableAttributes($model->getAttributes())));

        static::updated(function (Model $model): void {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $original = array_intersect_key($model->getOriginal(), $changes);

            $model->writeAudit('updated', $model->auditableAttributes($original), $model->auditableAttributes($changes));
        });

        static::deleted(fn (Model $model) => $model->writeAudit('deleted', $model->auditableAttributes($model->getOriginal()), []));
    }

    public function writeAudit(string $event, array $old, array $new): void
    {
        $request = request();

        AuditLog::create([
            'user_id' => auth()->id(),
            'impersonator_id' => session('impersonator_id'),
            'event' => $event,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'url' => $request?->fullUrl(),
            'ip_address' => $request?->ip() ? inet_pton($request->ip()) : null,
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'request_id' => $request?->attributes->get('request_id'),
        ]);
    }

    /** Strip sensitive attributes before persisting them to the audit table. */
    public function auditableAttributes(array $attributes): array
    {
        $excluded = array_merge(
            ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'biometric_key'],
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        );

        return array_diff_key($attributes, array_flip($excluded));
    }
}
