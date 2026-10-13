<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What a provider charges ReUp, per capability and network.
 *
 * One row is a commercial term — "for airtime on MTN, ClubKonnect bills us face
 * value less 3%" — rather than a price for one product. That shape is what lets a
 * single audited edit re-price every airtime denomination.
 *
 * ## Verified is not the same as configured
 *
 * `verified_at` is the whole point of the table. A rate somebody typed in is a
 * *configured assumption*; a rate confirmed against an invoice is a *verified
 * cost*. `isVerified()` distinguishes them, and the profit recorder uses that to
 * decide whether the margin it books is realised or merely estimated. Conflating
 * the two would report assumed profit as earned profit, which is the failure the
 * brief calls out explicitly.
 */
class ProviderNetworkCost extends Model
{
    protected $table = 'provider_network_costs';

    protected $fillable = [
        'uuid',
        'provider_id',
        'capability',
        'network',
        'discount_bps',
        'provider_fee_minor',
        'currency',
        'verified_at',
        'verified_by',
        'verification_note',
        'is_active',
    ];

    protected $casts = [
        'discount_bps' => 'integer',
        'provider_fee_minor' => 'integer',
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $cost) {
            if (empty($cost->uuid)) {
                $cost->uuid = (string) Str::uuid();
            }
        });
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Whether this figure has been confirmed against a real provider document.
     *
     * A row with no `verified_at` is an assumption, however carefully it was
     * entered.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Whether the figure is too old to rely on.
     *
     * A stale cost is treated as an unknown cost rather than as truth: the provider
     * may have re-priced, and selling on a stale cost is how a margin disappears
     * without anyone noticing.
     */
    public function isStale(?Carbon $at = null): bool
    {
        $maxAgeHours = (int) config('pricing.policy.max_age_hours', 168);

        if ($maxAgeHours <= 0 || $this->verified_at === null) {
            return false;
        }

        return $this->verified_at->addHours($maxAgeHours)->lessThan($at ?? now());
    }

    /**
     * The effective cost of a face value under this term.
     *
     * `face_value - (face_value × discount_bps / 10000) + provider_fee`, entirely in
     * integer kobo. Discount first, then the provider fee, matching the sequence in
     * the brief's calculation.
     */
    public function costFor(int $faceValueMinor): Money
    {
        $discountMinor = $this->discountMinorFor($faceValueMinor);

        return Money::fromMinor($faceValueMinor - $discountMinor + (int) $this->provider_fee_minor);
    }

    /**
     * The effective cost of a **list price**, where `discount_bps` is a multiplier.
     *
     * ## Why this is a second method rather than a use of `costFor()`
     *
     * The two express the same trade in opposite arithmetic, and mixing them is how
     * a cost gets discounted twice — which happened on the first attempt at this and
     * produced ₦15.00 for a ₦500 bundle.
     *
     *   * `costFor($faceValue)` treats `discount_bps` as the **percentage off**: airtime
     *     is bought at face value less 3%, so 300 bps off ₦1,000 costs ₦970.
     *   * `costOfList($listMinor)` treats `discount_bps` as the **multiplier paid**:
     *     the supplied schedule states data as `provider amount x 0.970`, which is
     *     9700 bps *of* the list price, so ₦500 costs ₦485.
     *
     * For 3% the two differ only in which side of the subtraction the rate sits on,
     * which is exactly why the mistake is easy to make and why each has its own
     * named method rather than one method with a flag.
     *
     * `discount_bps` above 10000 would mean paying more than list, which is not a
     * discount; it is clamped so a typo cannot multiply a cost upward silently.
     */
    public function costOfList(int $listMinor): Money
    {
        $bps = (int) $this->discount_bps;

        if ($bps <= 0) {
            return Money::fromMinor($listMinor + (int) $this->provider_fee_minor);
        }

        $bps = min($bps, 10000);

        $product = $listMinor * $bps;
        $quotient = intdiv($product, 10000);
        $remainder = $product % 10000;

        // Half-up, the rounding convention every other money calculation here uses.
        if ($remainder * 2 >= 10000) {
            $quotient++;
        }

        return Money::fromMinor($quotient + (int) $this->provider_fee_minor);
    }

    /** The discount this term takes off a face value, in kobo. */
    public function discountMinorFor(int $faceValueMinor): int
    {
        $bps = (int) $this->discount_bps;

        if ($bps <= 0) {
            return 0;
        }

        $product = $faceValueMinor * $bps;
        $quotient = intdiv($product, 10000);
        $remainder = $product % 10000;

        // Half-up, the rounding convention every other fee in the application uses.
        if (abs($remainder) * 2 >= 10000) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return $quotient;
    }

    /** The discount as a human percentage, e.g. "3.00%". */
    public function discountLabel(): string
    {
        return number_format(((int) $this->discount_bps) / 100, 2) . '%';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCapability($query, string $capability)
    {
        return $query->where('capability', $capability);
    }
}
