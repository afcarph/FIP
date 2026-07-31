<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Editable copy for push/email/SMS notifications so operations can adjust
 * wording without a deploy. Placeholders use `{{variable}}` syntax.
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
