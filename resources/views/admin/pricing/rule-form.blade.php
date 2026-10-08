@extends('admin.layouts.app')

@php
    $editing = $rule->exists;
    $amount = fn ($minor) => $minor === null ? '' : number_format($minor / 100, 2, '.', '');
    $percent = fn ($bps) => $bps === null ? '' : number_format($bps / 100, 2, '.', '');
@endphp

@section('title', $editing ? 'Edit pricing rule' : 'New pricing rule')
@section('page-title', $editing ? 'Edit pricing rule' : 'New pricing rule')
@section('page-description', 'A rule decides what a customer pays and what margin the platform earns.')

@section('content')
<div class="space-y-6" x-data="pricingRuleForm()">

    @if(session('error'))
        <div class="alert alert-destructive">{{ session('error') }}</div>
    @endif

    <form method="POST"
          action="{{ $editing ? route('admin.pricing.update', $rule) : route('admin.pricing.store') }}"
          @submit="if (requiresConfirmation && ! confirmed) { $event.preventDefault(); showConfirm = true; }">
        @csrf
        @if($editing)
            @method('PUT')
        @endif

        {{-- ======================= Subject ======================= --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">What this rule applies to</h2>
                <p class="card-description">The more specific the subject, the higher it ranks in resolution.</p>
            </div>

            <div class="card-content space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="form-label">Rule name</label>
                        <input type="text" name="name" id="name" class="form-input"
                               value="{{ old('name', $rule->name) }}" required maxlength="120">
                        @error('name') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="scope" class="form-label">Scope</label>
                        <select name="scope" id="scope" class="form-select" x-model="scope" required>
                            @foreach($scopes as $value => $label)
                                <option value="{{ $value }}" @selected(old('scope', $rule->scope) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('scope') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div x-show="scope === 'category'" x-cloak>
                        <label for="category_id" class="form-label">Category</label>
                        <select name="category_id" id="category_id" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('category_id', $rule->category_id) == $category->id)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('category_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div x-show="scope === 'provider'" x-cloak>
                        <label for="provider_id" class="form-label">Provider</label>
                        <select name="provider_id" id="provider_id" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}" @selected(old('provider_id', $rule->provider_id) == $provider->id)>
                                    {{ $provider->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('provider_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div x-show="scope === 'product'" x-cloak>
                        <label for="service_product_id" class="form-label">Product</label>
                        <select name="service_product_id" id="service_product_id" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" @selected(old('service_product_id', $rule->service_product_id) == $product->id)>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('service_product_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div x-show="scope === 'provider_product'" x-cloak>
                        <label for="provider_product_id" class="form-label">Provider product ID</label>
                        <input type="number" name="provider_product_id" id="provider_product_id" class="form-input"
                               value="{{ old('provider_product_id', $rule->provider_product_id) }}">
                        @error('provider_product_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- ======================= Markup ======================= --}}
        <div class="card mt-6">
            <div class="card-header">
                <h2 class="card-title">Markup</h2>
                <p class="card-description">
                    Markup applies to cost. A 25% markup on a ₦1,000 cost is a ₦1,250 price, which is a
                    20% gross margin — the two are different numbers and both are recorded.
                </p>
            </div>

            <div class="card-content space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="markup_type" class="form-label">Markup type</label>
                        <select name="markup_type" id="markup_type" class="form-select" x-model="markupType" required>
                            <option value="percentage" @selected(old('markup_type', $rule->markup_type) === 'percentage')>Percentage</option>
                            <option value="fixed" @selected(old('markup_type', $rule->markup_type) === 'fixed')>Fixed amount</option>
                            <option value="percentage_plus_fixed" @selected(old('markup_type', $rule->markup_type) === 'percentage_plus_fixed')>Percentage + fixed</option>
                            <option value="none" @selected(old('markup_type', $rule->markup_type) === 'none')>None (sell at cost)</option>
                        </select>
                    </div>

                    <div x-show="markupType === 'percentage' || markupType === 'percentage_plus_fixed'" x-cloak>
                        <label for="markup_percentage" class="form-label">Markup (%)</label>
                        <input type="number" step="0.01" min="0" max="1000" name="markup_percentage" id="markup_percentage"
                               class="form-input" x-model.number="markupPercent"
                               value="{{ old('markup_percentage', $percent($rule->markup_percentage_bps)) }}">
                        @error('markup_percentage') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div x-show="markupType === 'fixed' || markupType === 'percentage_plus_fixed'" x-cloak>
                        <label for="markup_fixed" class="form-label">Fixed markup (₦)</label>
                        <input type="number" step="0.01" min="0" name="markup_fixed" id="markup_fixed"
                               class="form-input"
                               value="{{ old('markup_fixed', $amount($rule->markup_fixed_minor)) }}">
                        @error('markup_fixed') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label for="minimum_profit" class="form-label">Minimum profit (₦)</label>
                        <input type="number" step="0.01" min="0" name="minimum_profit" id="minimum_profit"
                               class="form-input" x-model.number="minimumProfit"
                               value="{{ old('minimum_profit', $amount($rule->minimum_profit_minor)) }}">
                        <p class="form-hint">The rule applies whichever is larger: the markup above, or this floor.</p>
                    </div>

                    <div>
                        <label for="minimum_margin" class="form-label">Minimum margin (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="minimum_margin" id="minimum_margin"
                               class="form-input" x-model.number="minimumMargin"
                               value="{{ old('minimum_margin', $percent($rule->minimum_margin_bps)) }}">
                        <p class="form-hint">A sale below this margin is refused, unless the policy below says otherwise.</p>
                    </div>

                    <div>
                        <label for="maximum_markup" class="form-label">Maximum markup (₦)</label>
                        <input type="number" step="0.01" min="0" name="maximum_markup" id="maximum_markup"
                               class="form-input" value="{{ old('maximum_markup', $amount($rule->maximum_markup_minor)) }}">
                        <p class="form-hint">Caps a percentage rule on expensive items.</p>
                    </div>

                    <div>
                        <label for="on_unprofitable" class="form-label">When the floor cannot be met</label>
                        <select name="on_unprofitable" id="on_unprofitable" class="form-select" required>
                            <option value="unavailable" @selected(old('on_unprofitable', $rule->on_unprofitable) === 'unavailable')>Make it unavailable</option>
                            <option value="warning" @selected(old('on_unprofitable', $rule->on_unprofitable) === 'warning')>Sell anyway, flagged</option>
                            <option value="fallback" @selected(old('on_unprofitable', $rule->on_unprofitable) === 'fallback')>Use a fallback rule</option>
                            <option value="require_approval" @selected(old('on_unprofitable', $rule->on_unprofitable) === 'require_approval')>Require Super Admin approval</option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="fallback_rule_id" class="form-label">Fallback rule</label>
                        <select name="fallback_rule_id" id="fallback_rule_id" class="form-select">
                            <option value="">None</option>
                            @foreach($fallbackCandidates as $candidate)
                                <option value="{{ $candidate->id }}" @selected(old('fallback_rule_id', $rule->fallback_rule_id) == $candidate->id)>
                                    {{ $candidate->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="form-hint">Used only when the policy above is set to fall back.</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ======================= Bounds and rounding ======================= --}}
        <div class="card mt-6">
            <div class="card-header">
                <h2 class="card-title">Price bounds and rounding</h2>
                <p class="card-description">Profit is always recomputed after rounding, never from the pre-rounded price.</p>
            </div>

            <div class="card-content grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="minimum_selling_price" class="form-label">Minimum price (₦)</label>
                    <input type="number" step="0.01" min="0" name="minimum_selling_price" id="minimum_selling_price"
                           class="form-input" value="{{ old('minimum_selling_price', $amount($rule->minimum_selling_price_minor)) }}">
                </div>

                <div>
                    <label for="maximum_selling_price" class="form-label">Maximum price (₦)</label>
                    <input type="number" step="0.01" min="0" name="maximum_selling_price" id="maximum_selling_price"
                           class="form-input" value="{{ old('maximum_selling_price', $amount($rule->maximum_selling_price_minor)) }}">
                </div>

                <div>
                    <label for="rounding_step" class="form-label">Round to nearest (₦)</label>
                    <input type="number" step="0.01" min="0" name="rounding_step" id="rounding_step"
                           class="form-input" value="{{ old('rounding_step', $amount($rule->rounding_step_minor)) }}">
                    <p class="form-hint">Leave blank for no rounding. ₦10 rounds ₦1,237 to ₦1,240.</p>
                </div>

                <div>
                    <label for="rounding_mode" class="form-label">Rounding direction</label>
                    <select name="rounding_mode" id="rounding_mode" class="form-select" required>
                        <option value="nearest" @selected(old('rounding_mode', $rule->rounding_mode) === 'nearest')>Nearest</option>
                        <option value="up" @selected(old('rounding_mode', $rule->rounding_mode) === 'up')>Always up</option>
                        <option value="down" @selected(old('rounding_mode', $rule->rounding_mode) === 'down')>Always down</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- ======================= Customer fee ======================= --}}
        <div class="card mt-6">
            <div class="card-header">
                <h2 class="card-title">Customer fee</h2>
                <p class="card-description">Added to the price the customer pays, and counted as revenue.</p>
            </div>

            <div class="card-content space-y-4">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="customer_fee_enabled" value="1" class="form-checkbox"
                           @checked(old('customer_fee_enabled', $rule->customer_fee_enabled))>
                    Charge a customer fee on orders priced by this rule
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="customer_fee_type" class="form-label">Fee type</label>
                        <select name="customer_fee_type" id="customer_fee_type" class="form-select">
                            <option value="fixed" @selected(old('customer_fee_type', $rule->customer_fee_type) === 'fixed')>Fixed amount</option>
                            <option value="percentage" @selected(old('customer_fee_type', $rule->customer_fee_type) === 'percentage')>Percentage</option>
                        </select>
                    </div>

                    <div>
                        <label for="customer_fee_amount" class="form-label">Fee amount (₦)</label>
                        <input type="number" step="0.01" min="0" name="customer_fee_amount" id="customer_fee_amount"
                               class="form-input" value="{{ old('customer_fee_amount', $amount($rule->customer_fee_minor)) }}">
                    </div>

                    <div>
                        <label for="customer_fee_percentage" class="form-label">Fee percentage (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="customer_fee_percentage"
                               id="customer_fee_percentage" class="form-input"
                               value="{{ old('customer_fee_percentage', $percent($rule->customer_fee_bps)) }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- ======================= Promotion ======================= --}}
        <div class="card mt-6">
            <div class="card-header">
                <h2 class="card-title">Promotion</h2>
                <p class="card-description">
                    A discount applies only inside its date window. With no window configured, the discount is
                    inert — it never becomes a permanent unadvertised price cut.
                </p>
            </div>

            <div class="card-content grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="discount_percentage" class="form-label">Discount (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="discount_percentage" id="discount_percentage"
                           class="form-input" value="{{ old('discount_percentage', $percent($rule->discount_bps)) }}">
                </div>

                <div>
                    <label for="discount_amount" class="form-label">Discount amount (₦)</label>
                    <input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount"
                           class="form-input" value="{{ old('discount_amount', $amount($rule->discount_fixed_minor)) }}">
                </div>

                <div>
                    <label for="promotion_starts_at" class="form-label">Starts</label>
                    <input type="datetime-local" name="promotion_starts_at" id="promotion_starts_at" class="form-input"
                           value="{{ old('promotion_starts_at', $rule->promotion_starts_at?->format('Y-m-d\TH:i')) }}">
                </div>

                <div>
                    <label for="promotion_ends_at" class="form-label">Ends</label>
                    <input type="datetime-local" name="promotion_ends_at" id="promotion_ends_at" class="form-input"
                           value="{{ old('promotion_ends_at', $rule->promotion_ends_at?->format('Y-m-d\TH:i')) }}">
                </div>
            </div>

            <div class="card-content border-t">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="allow_negative_margin" value="1" class="form-checkbox mt-0.5"
                           @checked(old('allow_negative_margin', $rule->allow_negative_margin))>
                    <span>
                        Allow this rule to sell below cost
                        <span class="block text-xs text-muted-foreground">
                            A loss-making promotion must be enabled deliberately. Without this, a discount larger
                            than the markup is refused rather than silently absorbed.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        {{-- ======================= Preview ======================= --}}
        <div class="card mt-6">
            <div class="card-header">
                <h2 class="card-title">Preview</h2>
                <p class="card-description">
                    Computed with the same engine that prices real orders, so this cannot disagree with the result.
                </p>
            </div>

            <div class="card-content space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="sample_cost" class="form-label">Sample provider cost (₦)</label>
                        <input type="number" step="0.01" min="0.01" id="sample_cost" class="form-input"
                               x-model.number="sampleCost" value="1000">
                    </div>
                    <div>
                        <label for="sample_quantity" class="form-label">Quantity</label>
                        <input type="number" min="1" id="sample_quantity" class="form-input"
                               x-model.number="sampleQuantity" value="1">
                    </div>
                    <div class="flex items-end">
                        <button type="button" class="btn btn-outline" @click="runPreview()" :disabled="previewing">
                            <span x-show="! previewing">Preview price change</span>
                            <span x-show="previewing" x-cloak>Calculating…</span>
                        </button>
                    </div>
                </div>

                <template x-if="preview">
                    <div class="rounded-lg border p-4">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th class="text-right">Current</th>
                                    <th class="text-right">Proposed</th>
                                    <th class="text-right">Change</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Customer price</td>
                                    <td class="text-right tabular-nums" x-text="preview.current.formatted_price"></td>
                                    <td class="text-right tabular-nums" x-text="preview.proposed.formatted_price"></td>
                                    <td class="text-right tabular-nums" x-text="money(preview.delta.price_minor)"></td>
                                </tr>
                                <tr>
                                    <td>Gross profit</td>
                                    <td class="text-right tabular-nums" x-text="preview.current.formatted_profit"></td>
                                    <td class="text-right tabular-nums" x-text="preview.proposed.formatted_profit"></td>
                                    <td class="text-right tabular-nums" x-text="money(preview.delta.profit_minor)"></td>
                                </tr>
                                <tr>
                                    <td>Gross margin</td>
                                    <td class="text-right tabular-nums" x-text="preview.current.formatted_margin"></td>
                                    <td class="text-right tabular-nums" x-text="preview.proposed.formatted_margin"></td>
                                    <td class="text-right tabular-nums" x-text="bps(preview.delta.margin_bps)"></td>
                                </tr>
                            </tbody>
                        </table>

                        <template x-if="preview.would_become_unsellable">
                            <p class="mt-3 text-sm text-red-600">
                                This change would make the service unsellable at the sample cost:
                                <span x-text="preview.proposed.refusal_reason"></span>
                            </p>
                        </template>

                        <template x-if="preview.requires_confirmation">
                            <p class="mt-3 text-sm text-amber-700">
                                This is a significant price change (more than 5%, or it changes whether the service
                                can be sold). You will be asked to confirm before saving.
                            </p>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- ======================= Submit ======================= --}}
        <div class="card mt-6">
            <div class="card-content space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="priority" class="form-label">Priority</label>
                        <input type="number" min="0" name="priority" id="priority" class="form-input"
                               value="{{ old('priority', $rule->priority ?? 100) }}">
                        <p class="form-hint">Lower wins within the same scope.</p>
                    </div>

                    <div>
                        <label for="reason" class="form-label">Reason for this change</label>
                        <input type="text" name="reason" id="reason" class="form-input" maxlength="500"
                               value="{{ old('reason') }}" placeholder="Recorded in the pricing audit log">
                    </div>

                    <div class="flex items-center pt-6">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="is_active" value="1" class="form-checkbox"
                                   @checked(old('is_active', $rule->is_active ?? true))>
                            Active
                        </label>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="btn btn-primary" x-show="! showConfirm">
                        {{ $editing ? 'Save rule' : 'Create rule' }}
                    </button>

                    <template x-if="showConfirm">
                        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3">
                            <p class="text-sm text-amber-900">
                                Confirm this significant price change. It will take effect immediately for new orders.
                            </p>
                            <button type="submit" class="btn btn-primary btn-sm" @click="confirmed = true">
                                Confirm and save
                            </button>
                            <button type="button" class="btn btn-outline btn-sm" @click="showConfirm = false">
                                Cancel
                            </button>
                        </div>
                    </template>

                    <a href="{{ route('admin.pricing.rules') }}" class="btn btn-outline">Back to rules</a>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    function pricingRuleForm() {
        return {
            scope: @js(old('scope', $rule->scope)),
            markupType: @js(old('markup_type', $rule->markup_type)),
            markupPercent: Number(@js(old('markup_percentage', $percent($rule->markup_percentage_bps)))) || 0,
            minimumProfit: Number(@js(old('minimum_profit', $amount($rule->minimum_profit_minor)))) || 0,
            minimumMargin: Number(@js(old('minimum_margin', $percent($rule->minimum_margin_bps)))) || 0,
            sampleCost: 1000,
            sampleQuantity: 1,
            preview: null,
            previewing: false,
            showConfirm: false,
            confirmed: false,

            get requiresConfirmation() {
                return this.preview !== null && this.preview.requires_confirmation === true;
            },

            money(minor) {
                const value = Number(minor || 0) / 100;
                const sign = value > 0 ? '+' : '';
                return sign + '₦' + value.toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },

            bps(value) {
                const percent = Number(value || 0) / 100;
                const sign = percent > 0 ? '+' : '';
                return sign + percent.toFixed(2) + '%';
            },

            /**
             * Ask the server what this change would do.
             *
             * The figures come from the backend pricing engine — the browser never
             * computes a price, because a price computed in the browser is a price
             * the customer can change.
             */
            async runPreview() {
                this.previewing = true;

                try {
                    const form = this.$root.querySelector('form');
                    const data = new FormData(form);
                    const changes = {};

                    for (const [key, value] of data.entries()) {
                        if (['name', 'markup_type', 'markup_percentage', 'markup_fixed', 'minimum_profit',
                             'minimum_margin', 'maximum_markup', 'minimum_selling_price', 'maximum_selling_price',
                             'customer_fee_enabled', 'customer_fee_type', 'customer_fee_amount',
                             'customer_fee_percentage', 'discount_percentage', 'discount_amount',
                             'allow_negative_margin', 'rounding_step', 'rounding_mode', 'on_unprofitable',
                             'priority', 'is_active'].includes(key)) {
                            changes[key] = value;
                        }
                    }

                    const response = await fetch(@js(route('admin.pricing.preview')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': @js(csrf_token()),
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            rule_id: @js($rule->getKey() ?? 0),
                            sample_cost: this.sampleCost,
                            quantity: this.sampleQuantity,
                            changes: changes,
                        }),
                    });

                    if (! response.ok) {
                        this.preview = null;
                        alert('Could not calculate a preview. Check the values and try again.');
                        return;
                    }

                    this.preview = await response.json();
                    this.confirmed = false;
                } catch (error) {
                    this.preview = null;
                } finally {
                    this.previewing = false;
                }
            },
        };
    }
</script>
@endpush
