<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Support\SpreadsheetCell;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    public function test_security_headers_on_pages_and_api(): void
    {
        $store = $this->createStore(['slug' => 'awa']);
        foreach ([$this->get('http://awa.localhost/'), $this->storeApi($store, 'GET', 'auth/me')] as $response) {
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        }
    }

    public function test_spreadsheet_cells_never_run_as_formulas(): void
    {
        $this->assertSame(["'=HYPERLINK(\"https://evil\")", "'@SUM(A1)", "'-2+3", 'Fatou', '+225 07 00 00 00 01', -1500, 12], SpreadsheetCell::row(['=HYPERLINK("https://evil")', '@SUM(A1)', '-2+3', 'Fatou', '+225 07 00 00 00 01', -1500, 12]));
    }

    public function test_carrier_links_are_web_links_only(): void
    {
        $this->assertNull(Shipment::webUrl('javascript:alert(document.cookie)'));
        $this->assertNull(Shipment::webUrl('data:text/html,<script>'));
        $this->assertSame('https://suivi.jibiat.com/c/123', Shipment::webUrl(' https://suivi.jibiat.com/c/123 '));
        $this->assertNull((new Shipment(['carrier_tracking_url' => 'javascript:alert(1)']))->carrier_tracking_url);
    }
}
