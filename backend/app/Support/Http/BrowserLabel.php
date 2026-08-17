<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * A readable name for a browser registration.
 *
 * A web sign-in used to be stored under the raw User-Agent string, so a
 * person's own device list read as a wall of `Mozilla/5.0 (Macintosh; Intel
 * Mac OS X 10_15_7)…` with no way to tell one row from another. That list is
 * where somebody goes to revoke a session they do not recognise, and it could
 * not answer the only question being asked of it.
 *
 * Two things this deliberately does not do.
 *
 * It does not try to be a user-agent parser. Those are large, wrong often, and
 * kept alive by a stream of new strings; this recognises the handful of
 * families that actually sign in and says "Web browser" for everything else. A
 * vague label is a much smaller failure than a confidently wrong one, and the
 * device_uuid is what identifies a row regardless.
 *
 * It does not keep the original string. The full User-Agent is a fingerprint —
 * fonts, engine builds, minor versions — and storing it forever to render a
 * label is more than the job needs. What is kept is roughly what a person
 * would say out loud: "Chrome on macOS".
 */
final class BrowserLabel
{
    /**
     * Order matters. Edge and Opera both claim to be Chrome, Chrome claims to
     * be Safari, and every one of them claims to be Mozilla — so the most
     * specific families have to be tested first.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const BROWSERS = [
        ['/\bEdgA?\//i', 'Edge'],
        ['/\bOPR\/|\bOpera\b/i', 'Opera'],
        ['/\bSamsungBrowser\//i', 'Samsung Internet'],
        ['/\bFirefox\/|\bFxiOS\//i', 'Firefox'],
        ['/\bCriOS\//i', 'Chrome'],
        ['/\bChrome\//i', 'Chrome'],
        ['/\bSafari\//i', 'Safari'],
    ];

    /** @var list<array{0: string, 1: string}> */
    private const PLATFORMS = [
        ['/\biPhone\b/i', 'iPhone'],
        ['/\biPad\b/i', 'iPad'],
        ['/\bAndroid\b/i', 'Android'],
        ['/\bCrOS\b/i', 'ChromeOS'],
        ['/\bWindows NT\b/i', 'Windows'],
        ['/\bMac OS X\b|\bMacintosh\b/i', 'macOS'],
        ['/\bLinux\b/i', 'Linux'],
    ];

    /**
     * "Chrome on macOS", "Safari on iPhone", or "Web browser" when neither
     * half can be read honestly.
     */
    public static function from(?string $userAgent): string
    {
        $agent = trim((string) $userAgent);

        if ($agent === '') {
            return 'Web browser';
        }

        $browser = self::match(self::BROWSERS, $agent);
        $platform = self::match(self::PLATFORMS, $agent);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            // A platform on its own is still worth more than nothing: "a
            // browser on macOS" narrows it for somebody scanning their own list.
            $platform !== null => "Browser on {$platform}",
            default => 'Web browser',
        };
    }

    /** @param list<array{0: string, 1: string}> $candidates */
    private static function match(array $candidates, string $agent): ?string
    {
        foreach ($candidates as [$pattern, $label]) {
            if (preg_match($pattern, $agent) === 1) {
                return $label;
            }
        }

        return null;
    }
}
