<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Prove that mail actually leaves the building.
 *
 * The failure this exists for is silence. A misconfigured mailer does not
 * throw: MailHog accepts everything and keeps it, a queued mail fails inside a
 * worker nobody is watching, and a password reset that never arrives looks
 * identical from the application's side to one that did. Production ran that
 * way for weeks.
 *
 * So this sends one message, synchronously, and reports what the transport
 * said. Synchronous on purpose — a queued send would return "queued" and tell
 * you nothing about whether the relay accepted it, which is the only question
 * being asked.
 *
 * It prints no credentials. The point is to be runnable by whoever holds them
 * without those ever passing through anybody else's hands.
 */
class SendTestMail extends Command
{
    protected $signature = 'fip:mail-test {recipient : Where to send it}';

    protected $description = 'Send one real message and report whether the transport accepted it';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("[{$recipient}] is not an email address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $host = (string) config('mail.mailers.smtp.host');
        $from = (string) config('mail.from.address');

        $this->line("mailer:    {$mailer}".($mailer === 'smtp' ? " via {$host}" : ''));
        $this->line("from:      {$from}");
        $this->line("to:        {$recipient}");
        $this->newLine();

        // Said before sending rather than after: if the transport is a catcher
        // the send will succeed and prove nothing, and the person running this
        // should know that before they go looking in an inbox.
        if (in_array(mb_strtolower($host), ['mailhog', 'mailpit', 'maildev', 'localhost', '127.0.0.1'], true)) {
            $this->warn('This host is a development catcher. It will accept the message and keep it.');
            $this->warn('Nothing will arrive in a real inbox, however green the result below looks.');
            $this->newLine();
        }

        try {
            Mail::raw(
                "This is a delivery test from the Fuel Intelligence Platform.\n\n"
                .'Sent at '.now()->toDayDateTimeString().' ('.config('app.timezone').")\n"
                .'From environment: '.config('app.env')."\n\n"
                ."If this arrived, password resets and notifications can reach people.\n"
                ."Check the message headers for SPF and DKIM results — accepted by the relay\n"
                .'is not the same as accepted by the recipient.',
                static fn ($message) => $message
                    ->to($recipient)
                    ->subject('FIP delivery test — '.now()->format('H:i:s')),
            );
        } catch (Throwable $e) {
            // The message, not the trace: this is usually authentication or a
            // blocked port, and both say so in one line.
            $this->error('The transport refused it: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('The transport accepted the message.');
        $this->line('That means the relay took it, not that it reached an inbox. Confirm arrival,');
        $this->line('then check the headers say SPF=pass and DKIM=pass before trusting it.');

        return self::SUCCESS;
    }
}
