<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refuse to deploy a configuration that is unsafe in production.
 *
 * Every check here is for something that is invisible when it is wrong. A
 * debug bar left on, a wildcard CORS policy, an APP_KEY carried over from a
 * tutorial — the application starts, serves traffic and passes its tests in
 * all of those states. The failure only shows up as an incident.
 *
 * Run it in the deploy pipeline before traffic is switched:
 *
 *     php artisan fip:check-config --env=production
 *
 * Exit code 1 on any failure, so a pipeline stops on it.
 */
class CheckProductionConfig extends Command
{
    protected $signature = 'fip:check-config
                            {--production : Apply the production-only checks regardless of APP_ENV}';

    protected $description = 'Validate configuration and secrets before a deployment.';

    /** @var list<array{level: string, name: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $production = (bool) $this->option('production') || app()->environment('production');

        $this->line('Checking configuration'.($production ? ' for production' : '').'…');
        $this->newLine();

        $this->checkAppKey();
        $this->checkDebug($production);
        $this->checkAppUrl($production);
        $this->checkDatabase();
        $this->checkCors($production);
        $this->checkSessionAndCookies($production);
        $this->checkDoeIngest();

        return $this->report();
    }

    // -- checks ---------------------------------------------------------------

    private function checkAppKey(): void
    {
        $key = (string) config('app.key');

        if ($key === '') {
            $this->bad('APP_KEY', 'Not set. Every encrypted value and signed cookie depends on it.');

            return;
        }

        // A key shared between environments means a session cookie minted on
        // staging is valid in production.
        if (in_array($key, ['base64:'.base64_encode(str_repeat('a', 32)), 'SomeRandomString'], true)) {
            $this->bad('APP_KEY', 'Looks like a placeholder. Generate one with `php artisan key:generate`.');

            return;
        }

        $this->ok('APP_KEY', 'set');
    }

    private function checkDebug(bool $production): void
    {
        if (config('app.debug') === true) {
            $production
                ? $this->bad('APP_DEBUG', 'Enabled. Stack traces expose environment variables, including credentials.')
                : $this->caution('APP_DEBUG', 'Enabled — correct outside production, fatal in it.');

            return;
        }

        $this->ok('APP_DEBUG', 'off');
    }

    private function checkAppUrl(bool $production): void
    {
        $url = (string) config('app.url');

        if ($url === '' || str_contains($url, 'localhost')) {
            $production
                ? $this->bad('APP_URL', "Still {$url}. Signed URLs and password-reset links are built from it.")
                : $this->ok('APP_URL', $url);

            return;
        }

        if ($production && ! str_starts_with($url, 'https://')) {
            $this->bad('APP_URL', "Not HTTPS ({$url}). Links mailed to users would downgrade the connection.");

            return;
        }

        $this->ok('APP_URL', $url);
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->select('select 1');
        } catch (Throwable $exception) {
            $this->bad('database', 'Unreachable: '.$exception->getMessage());

            return;
        }

        $connection = (string) config('database.default');
        $password = (string) config("database.connections.{$connection}.password");

        if ($password === '' && $connection !== 'sqlite') {
            $this->bad('database', 'No password set on the connection.');

            return;
        }

        $this->ok('database', "reachable over {$connection}");
    }

    private function checkCors(bool $production): void
    {
        /** @var list<string> $origins */
        $origins = (array) config('cors.allowed_origins');

        if (in_array('*', $origins, true)) {
            // With supports_credentials on, a wildcard is both a security
            // problem and invalid — browsers reject the combination, so this
            // fails loudly in one environment and silently in another.
            $this->bad('CORS', 'allowed_origins contains "*", which is invalid alongside credentials.');

            return;
        }

        if ($origins === []) {
            $this->bad('CORS', 'No allowed origins configured; every browser client will be refused.');

            return;
        }

        $insecure = array_filter(
            $origins,
            static fn (string $origin): bool => str_starts_with($origin, 'http://')
                && ! str_contains($origin, 'localhost')
                && ! str_contains($origin, '127.0.0.1'),
        );

        if ($production && $insecure !== []) {
            $this->bad('CORS', 'Plain-HTTP origins allowed: '.implode(', ', $insecure));

            return;
        }

        $this->ok('CORS', count($origins).' origin(s) allowed');
    }

    private function checkSessionAndCookies(bool $production): void
    {
        if ($production && config('session.secure') !== true) {
            $this->caution('session.secure', 'Off. Session cookies may be sent over plain HTTP.');
        } else {
            $this->ok('session.secure', 'on or not applicable');
        }

        if (config('session.http_only') !== true) {
            $this->caution('session.http_only', 'Off. Session cookies are readable from JavaScript.');
        }
    }

    private function checkDoeIngest(): void
    {
        $path = (string) config('doe.pdf_archive_path');

        if ($path === '') {
            $this->caution('DOE archive', 'No path configured; the health endpoint cannot report storage.');

            return;
        }

        if (! is_dir($path)) {
            // Not a failure: a fresh instance has not imported anything yet.
            $this->caution('DOE archive', "{$path} does not exist yet.");

            return;
        }

        // Readable, not writable. The API only reports on the archive — the
        // ingest owns it and mounts it read-only here on purpose, so checking
        // for write access fails a correctly configured host.
        is_readable($path)
            ? $this->ok('DOE archive', $path)
            : $this->bad('DOE archive', "{$path} is not readable; the health endpoint cannot report on it.");
    }

    // -- output ---------------------------------------------------------------

    private function ok(string $name, string $detail): void
    {
        $this->results[] = ['level' => 'pass', 'name' => $name, 'detail' => $detail];
        $this->line("  <fg=green>✓</> {$name} — {$detail}");
    }

    private function caution(string $name, string $detail): void
    {
        $this->results[] = ['level' => 'warn', 'name' => $name, 'detail' => $detail];
        $this->line("  <fg=yellow>!</> {$name} — {$detail}");
    }

    private function bad(string $name, string $detail): void
    {
        $this->results[] = ['level' => 'fail', 'name' => $name, 'detail' => $detail];
        $this->line("  <fg=red>✗</> {$name} — {$detail}");
    }

    private function report(): int
    {
        $failures = array_filter($this->results, static fn (array $r): bool => $r['level'] === 'fail');
        $warnings = array_filter($this->results, static fn (array $r): bool => $r['level'] === 'warn');

        $this->newLine();

        if ($failures !== []) {
            $this->error(count($failures).' check(s) failed. Do not deploy this configuration.');

            return self::FAILURE;
        }

        $warnings === []
            ? $this->info('All checks passed.')
            : $this->comment(count($warnings).' warning(s); no failures.');

        return self::SUCCESS;
    }
}
