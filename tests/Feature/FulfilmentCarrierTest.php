<?php

namespace Pine\Commerce\Tests\Feature;

use Pine\Commerce\Mail\CustomerCompletedOrder;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Ready to send › Carrier: a drop-down of the store's carriers (config commerce.shipping.carriers) instead of a typed
 * box pre-filled with "DPD", whose browser suggestions only ever offered DPD. "Other…" takes a typed carrier name.
 */
class FulfilmentCarrierTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_ready_to_send_offers_every_configured_carrier_and_no_hard_coded_default(): void
    {
        config(['commerce.shipping.carriers' => ['Royal Mail' => 'https://rm.example/{number}', 'DHL' => 'https://dhl.example/{number}'], 'commerce.shipping.default_carrier' => null]);
        [, , $order] = $this->catalogue();

        $html = $this->actingAs($this->neutralAdmin())->get(route('admin.orders.show', $order))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<select[^>]*name="tracking_carrier"#', $html);
        $this->assertStringContainsString('<option value="Royal Mail"', $html);
        $this->assertStringContainsString('<option value="DHL"', $html);
        $this->assertStringContainsString('<option value="__other"', $html);
        $this->assertStringNotContainsString('value="DPD"', $html, 'not configured, not used before, not pre-filled');
        $this->assertStringNotContainsString('<datalist', $html);
    }

    public function test_the_default_carrier_is_pre_selected_and_a_carrier_used_before_is_offered(): void
    {
        config(['commerce.shipping.carriers' => ['Royal Mail' => 'https://rm.example/{number}', 'DPD' => 'https://dpd.example/{number}'], 'commerce.shipping.default_carrier' => 'Royal Mail']);
        [, , $order] = $this->catalogue();
        \Pine\Commerce\Models\Order::create(['email' => 'bob@example.test', 'status' => 'completed', 'total' => 10, 'tracking_carrier' => 'Yodel', 'tracking_number' => 'JD1']);

        $html = $this->actingAs($this->neutralAdmin())->get(route('admin.orders.show', $order))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option value="Royal Mail"\s+selected#', $html);
        $this->assertStringContainsString('<option value="Yodel"', $html);
    }

    public function test_other_saves_the_typed_carrier_name(): void
    {
        [, , $order] = $this->catalogue();

        $this->actingAs($this->neutralAdmin())->post(route('admin.orders.fulfil', $order), [
            'tracking_carrier' => '__other', 'tracking_carrier_other' => ' Yodel ', 'tracking_number' => 'JD0001',
        ])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('Yodel', $order->tracking_carrier);
        $this->assertSame('JD0001', $order->tracking_number);
    }

    public function test_tracking_links_come_from_the_configured_carriers_first(): void
    {
        config(['commerce.shipping.carriers' => ['Royal Mail' => 'https://rm.example/track/{number}', 'Local Courier' => 'https://local.example/?n={number}']]);

        $this->assertSame('https://rm.example/track/AB%20123', CustomerCompletedOrder::trackingUrl('Royal Mail', 'AB 123'));
        $this->assertSame('https://rm.example/track/AB123', CustomerCompletedOrder::trackingUrl('Royal Mail Tracked 48', 'AB123'));
        $this->assertSame('https://local.example/?n=9', CustomerCompletedOrder::trackingUrl('local courier', '9'));
        $this->assertSame('https://track.dpd.co.uk/parcels/15501', CustomerCompletedOrder::trackingUrl('DPD', '15501'), 'built-in list as the fallback');
        $this->assertNull(CustomerCompletedOrder::trackingUrl('Royal Mail', ''));
    }
}
