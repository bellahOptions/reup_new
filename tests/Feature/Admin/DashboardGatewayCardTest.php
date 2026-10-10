<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin dashboard's gateway and provider cards.
 *
 * ## The distinction these assert
 *
 * The dashboard had one card headed **"Gateway status"** listing Paystack *and*
 * ClubKonnect, with both balances rendered the same way. ClubKonnect is not a
 * payment gateway — it is the upstream that vends airtime, data, cable and PINs.
 * Money never arrives through it; it is spent with it.
 *
 * That is not a naming preference. A card that presents a vending float
 * alongside a gateway balance, under a heading that says "gateway", invites
 * exactly one reading: that the figure is platform revenue. It also duplicated
 * the "Vending providers" card below, so the same number appeared twice under
 * two different labels.
 *
 * So the two directions of travel are asserted separately: a gateway takes money
 * *in*, a vending provider pays money *out*.
 */
class DashboardGatewayCardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    public function test_the_gateway_card_is_named_for_what_it_holds(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Payment gateways', false)
            ->assertSee('Where customer money arrives', false);
    }

    public function test_a_vending_provider_is_not_presented_as_a_payment_gateway(): void
    {
        /*
         * ClubKonnect belongs under the vending heading, and must not appear in
         * the gateway card. Asserted on the *card*, not the page: the name
         * legitimately appears elsewhere on the dashboard, so a page-wide
         * assertion would prove nothing either way.
         */
        $html = $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $gatewayCard = $this->cardContaining($html, 'Payment gateways');

        $this->assertNotNull($gatewayCard, 'The payment-gateway card is missing.');
        $this->assertStringContainsString('Paystack', $gatewayCard);
        $this->assertStringNotContainsString(
            'ClubKonnect',
            $gatewayCard,
            'ClubKonnect vends; it does not take payments. Listing it under "Payment gateways" reads as revenue.'
        );
    }

    public function test_the_vending_card_says_which_direction_the_money_goes(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $vendingCard = $this->cardContaining($html, 'Vending providers');

        $this->assertNotNull($vendingCard, 'The vending-providers card is missing.');
        $this->assertStringContainsString(
            'not payment gateways',
            $vendingCard,
            'The vending card must say plainly that these are not gateways.'
        );
    }

    public function test_the_dashboard_renders_without_encoding_damage(): void
    {
        /*
         * The naira sign in the admin dashboard shipped as a doubled sequence —
         * UTF-8 decoded as Windows-1252 and re-encoded — so every figure on the
         * page was wrong. No other test in the suite looks at what the browser
         * would actually receive.
         *
         * The damaged sequence is deliberately not spelled out, here or below:
         * writing it literally would make this file contain the very thing
         * `SourceEncodingTest` scans for. See the note on `$mangledLead`.
         */
        $html = $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        /*
         * The mangled lead character is built from its codepoint rather than
         * written literally. Spelled out, this file would itself contain the
         * sequence `SourceEncodingTest` looks for — and the honest options would
         * then be to exempt this file (losing coverage of it) or to let the guard
         * fail. `\u{00E2}` sidesteps both: the bytes under test exist only at
         * runtime.
         */
        $mangledLead = "\u{00E2}";

        $this->assertStringNotContainsString($mangledLead, $html, 'The dashboard contains mojibake.');
        $this->assertStringContainsString("\u{20A6}", $html, 'The naira sign is missing from the dashboard entirely.');
    }

    /**
     * The outermost element containing the given heading text.
     *
     * A crude but sufficient split on the heading: each card is a single
     * top-level `<div class="card …">`, and the assertions above only need to
     * know which card a name appears in.
     */
    private function cardContaining(string $html, string $heading): ?string
    {
        $position = strpos($html, $heading);

        if ($position === false) {
            return null;
        }

        // Walk back to the start of the card, and forward to the end of it.
        $start = strrpos(substr($html, 0, $position), '<div class="card');
        $start = $start === false ? 0 : $start;

        $end = strpos($html, '</div>', $position);

        // Search forward for the next card boundary so the slice covers the
        // whole card rather than the first nested div.
        $nextCard = strpos($html, '<div class="card', $position);
        $end = $nextCard === false ? strlen($html) : $nextCard;

        return substr($html, $start, $end - $start);
    }
}
