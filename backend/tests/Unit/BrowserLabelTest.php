<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Http\BrowserLabel;
use PHPUnit\Framework\TestCase;

/**
 * What a browser registration is called.
 *
 * The point of these is not parsing accuracy — it is that the label says
 * something a person can recognise on the screen where they revoke a session,
 * and that it never says something confidently wrong. Every browser claims to
 * be several others, so the ordering below is the whole substance of the class.
 */
class BrowserLabelTest extends TestCase
{
    public function test_it_names_a_browser_and_the_thing_it_runs_on(): void
    {
        $this->assertSame('Chrome on macOS', BrowserLabel::from(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
        ));
    }

    public function test_safari_is_not_reported_as_chrome(): void
    {
        // Chrome's string ends in "Safari/537.36". Testing Safari before
        // Chrome would label every Chrome user as a Safari one.
        $this->assertSame('Safari on macOS', BrowserLabel::from(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        ));
    }

    public function test_edge_is_not_reported_as_chrome(): void
    {
        $this->assertSame('Edge on Windows', BrowserLabel::from(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 Edg/128.0',
        ));
    }

    public function test_opera_is_not_reported_as_chrome(): void
    {
        $this->assertSame('Opera on Windows', BrowserLabel::from(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 OPR/114.0',
        ));
    }

    public function test_chrome_on_ios_is_still_chrome(): void
    {
        // iOS Chrome identifies as CriOS and carries no "Chrome/" token.
        $this->assertSame('Chrome on iPhone', BrowserLabel::from(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0 Mobile/15E148 Safari/604.1',
        ));
    }

    public function test_an_ipad_is_not_called_a_mac(): void
    {
        $this->assertSame('Safari on iPad', BrowserLabel::from(
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        ));
    }

    public function test_android_beats_the_linux_it_is_built_on(): void
    {
        $this->assertSame('Chrome on Android', BrowserLabel::from(
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36',
        ));
    }

    public function test_an_unrecognised_agent_says_so_rather_than_guessing(): void
    {
        // A vague label is a far smaller failure than a confident wrong one,
        // and the device_uuid identifies the row either way.
        $this->assertSame('Web browser', BrowserLabel::from('curl/8.4.0'));
    }

    public function test_a_platform_alone_still_narrows_it(): void
    {
        $this->assertSame('Browser on Windows', BrowserLabel::from('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'));
    }

    public function test_nothing_at_all_is_handled(): void
    {
        $this->assertSame('Web browser', BrowserLabel::from(null));
        $this->assertSame('Web browser', BrowserLabel::from('   '));
    }
}
