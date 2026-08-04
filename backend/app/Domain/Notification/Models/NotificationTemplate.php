<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Editable copy for push/email/SMS notifications so operations can adjust
 * wording without a deploy. Placeholders use `{{variable}}` syntax.
 *
 * @property int $id
 * @property string $code
 * @property string $channel
 * @property string $title
 * @property string $body
 * @property array<array-key, mixed>|null $variables
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationTemplate whereVariables($value)
 *
 * @mixin \Eloquent
 */
class NotificationTemplate extends Model
{
    protected $fillable = ['code', 'channel', 'title', 'body', 'variables', 'is_active'];

    protected function casts(): array
    {
        return ['variables' => 'array', 'is_active' => 'boolean'];
    }

    public static function render(string $code, array $values, string $channel = 'push'): ?array
    {
        $template = static::where('code', $code)->where('channel', $channel)->where('is_active', true)->first();

        if ($template === null) {
            return null;
        }

        return [
            'title' => $template->interpolate($template->title, $values),
            'body' => $template->interpolate($template->body, $values),
        ];
    }

    public function interpolate(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $text = str_replace('{{'.$key.'}}', (string) $value, $text);
        }

        // Drop any placeholder the caller did not supply.
        return trim((string) preg_replace('/\{\{\w+\}\}/', '', $text));
    }
}
