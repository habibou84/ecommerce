<?php

namespace Jibiat\Socle\Tests;

use Jibiat\Socle\Money;
use Jibiat\Socle\Webhooks\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    public function test_a_signed_body_verifies(): void
    {
        $signature = WebhookSignature::sign('secret', 1_700_000_000, '{"a":1}');

        $this->assertStringStartsWith('sha256=', $signature);
        $this->assertTrue(WebhookSignature::verify('secret', '1700000000', '{"a":1}', $signature, 1_700_000_100));
    }

    public function test_a_tampered_body_or_wrong_secret_is_rejected(): void
    {
        $signature = WebhookSignature::sign('secret', 1_700_000_000, '{"a":1}');

        $this->assertFalse(WebhookSignature::verify('secret', '1700000000', '{"a":2}', $signature, 1_700_000_000));
        $this->assertFalse(WebhookSignature::verify('other', '1700000000', '{"a":1}', $signature, 1_700_000_000));
        $this->assertFalse(WebhookSignature::verify('secret', null, '{"a":1}', $signature, 1_700_000_000));
    }

    public function test_an_old_timestamp_is_rejected(): void
    {
        $signature = WebhookSignature::sign('secret', 1_700_000_000, 'x');

        $this->assertFalse(WebhookSignature::verify('secret', '1700000000', 'x', $signature, 1_700_000_000 + WebhookSignature::TOLERANCE_SECONDS + 1));
    }

    public function test_money_is_formatted_with_ordinary_spaces(): void
    {
        $this->assertSame('12 500 F', Money::format(12500));
        $this->assertSame('1 000 000 FCFA', Money::format(1_000_000, 'FCFA'));
    }
}
