<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Whether the pre-deploy check notices that mail cannot leave the building.
 *
 * Regression cover for a live fault: production ran for weeks sending every
 * password reset, alert and notification to a MailHog container nobody reads.
 * Nothing failed, nothing logged, and nobody could have told from the outside
 * — config/mail.php simply defaults the host to `mailhog`, and the deployment
 * never set MAIL_HOST.
 *
 * A silent failure needs a loud check, and `fip:check-config --production` is
 * the gate the deployment guide already tells people to run.
 */
class MailDeliverabilityCheckTest extends TestCase
{
    /**
     * Run the checker and return what it printed.
     *
     * Artisan::call rather than $this->artisan(): the latter returns a pending
     * command that only executes when an expectation is attached to it, so the
     * output buffer stays empty and every assertion here would pass vacuously.
     */
    private function check(bool $production = true): string
    {
        Artisan::call('fip:check-config', $production ? ['--production' => true] : []);

        return Artisan::output();
    }

    public function test_a_development_catcher_fails_the_production_check(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailhog']);

        $output = $this->check();

        $this->assertStringContainsString('mail', $output);
        $this->assertStringContainsString('development mail catcher', $output);
        $this->assertStringContainsString('Do not deploy', $output);
    }

    public function test_the_other_catchers_are_caught_too(): void
    {
        // Mailpit is MailHog's successor and the likeliest replacement to be
        // dropped in without anyone revisiting the production value.
        foreach (['mailpit', 'maildev', 'localhost', '127.0.0.1'] as $host) {
            config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => $host]);

            $this->assertStringContainsString(
                'development mail catcher',
                $this->check(),
                "[{$host}] should be refused in production.",
            );
        }
    }

    public function test_the_same_catcher_is_fine_outside_production(): void
    {
        // The check must not cry wolf locally, where MailHog is the right
        // answer and a failing gate would train people to ignore it.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailhog']);

        $output = $this->check(production: false);

        $this->assertStringContainsString('development catcher', $output);
        $this->assertDoesNotMatchRegularExpression('/✗\s+mail/u', $output);
    }

    public function test_a_mailer_that_sends_nothing_fails_production(): void
    {
        config(['mail.default' => 'log']);

        $this->assertStringContainsString('nothing is ever sent', $this->check());
    }

    public function test_an_empty_host_fails(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);

        $this->assertStringContainsString('nowhere to go', $this->check());
    }

    public function test_a_real_host_without_credentials_is_a_caution_not_a_failure(): void
    {
        // Half-finished rather than dangerous: the host is real, so this is
        // worth flagging without blocking a deploy that may use IP relay.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.sendgrid.net',
            'mail.mailers.smtp.username' => null,
            // Aligned so this test is about credentials alone. Without it the
            // sender check fires on whatever MAIL_FROM_ADDRESS the developer's
            // own .env happens to hold, and the two faults get confused.
            'mail.from.address' => 'no-reply@nelleeph.com',
            'app.url' => 'https://fip.nelleeph.com',
        ]);

        $output = $this->check();

        // Asserted on the mail line itself, not the command's overall verdict:
        // other checks fail in the test environment, so "Do not deploy" says
        // nothing about whether mail was treated as a caution or a failure.
        $this->assertStringContainsString('MAIL_USERNAME is empty', $output);
        $this->assertMatchesRegularExpression('/!\s+mail/', $output);
        $this->assertDoesNotMatchRegularExpression('/✗\s+mail/u', $output);
    }

    // ------------------------------------------------- the sending domain ---

    public function test_sending_as_a_domain_we_do_not_control_fails_production(): void
    {
        // The live fault: production sent as no-reply@fip.ph while serving
        // fip.nelleeph.com. fip.ph belongs to somebody else — it resolves to a
        // parking host and publishes no SPF — so every message failed
        // authentication and carried a third party's domain on it.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.example.com',
            'mail.mailers.smtp.username' => 'someone',
            'mail.from.address' => 'no-reply@fip.ph',
            'app.url' => 'https://fip.nelleeph.com',
        ]);

        $output = $this->check();

        $this->assertStringContainsString('mail from', $output);
        $this->assertStringContainsString('fails SPF and DKIM', $output);
        $this->assertStringContainsString('Do not deploy', $output);
    }

    public function test_a_sender_on_the_deployments_own_domain_passes(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.example.com',
            'mail.mailers.smtp.username' => 'someone',
            'mail.from.address' => 'no-reply@nelleeph.com',
            'app.url' => 'https://fip.nelleeph.com',
        ]);

        $this->assertStringNotContainsString('fails SPF and DKIM', $this->check());
    }

    public function test_a_subdomain_sender_aligns_with_its_parent(): void
    {
        // mail.nelleeph.com sending for fip.nelleeph.com is the normal shape
        // once a provider is configured, and must not be reported as foreign.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.example.com',
            'mail.mailers.smtp.username' => 'someone',
            'mail.from.address' => 'no-reply@mail.nelleeph.com',
            'app.url' => 'https://fip.nelleeph.com',
        ]);

        $this->assertStringNotContainsString('fails SPF and DKIM', $this->check());
    }

    public function test_an_empty_sender_is_refused(): void
    {
        config(['mail.from.address' => '', 'app.url' => 'https://fip.nelleeph.com']);

        $this->assertStringContainsString('no sender', $this->check());
    }

    public function test_the_sender_check_needs_no_network(): void
    {
        // Deliberately not a DNS lookup: a pre-deploy gate has to give the
        // same answer on a laptop with no network as it does on the host.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.example.com',
            'mail.mailers.smtp.username' => 'someone',
            'mail.from.address' => 'no-reply@definitely-not-registered-'.uniqid().'.invalid',
            'app.url' => 'https://fip.nelleeph.com',
        ]);

        $this->assertStringContainsString('fails SPF and DKIM', $this->check());
    }

    public function test_a_configured_transport_passes(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.sendgrid.net',
            'mail.mailers.smtp.username' => 'apikey',
        ]);

        $output = $this->check();

        $this->assertStringContainsString('smtp.sendgrid.net', $output);
        $this->assertStringNotContainsString('development mail catcher', $output);
    }
}
