@extends('admin.layouts.app')

@section('title', 'Bulk pricing update')
@section('page-title', 'Bulk pricing update')
@section('page-description', 'Apply one change to every rule in a category or provider.')

@section('content')
<div class="space-y-6">

    <div class="alert">
        <p class="text-sm">
            Every affected rule is versioned and audited individually. A bulk edit is still a series of separate
            financial decisions, so the history shows which rule changed to what.
        </p>
    </div>

    <form method="POST" action="{{ route('admin.pricing.bulk.update') }}"
          onsubmit="return confirm('Apply this pricing change? It takes effect immediately for new orders.')">
        @csrf

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Which rules</h2>
            </div>

            <div class="card-content grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="scope" class="form-label">Scope</label>
                    <select name="scope" id="scope" class="form-select" required>
                        <option value="category">All rules for a service category</option>
                        <option value="provider">All rules for a provider</option>
                    </select>
                    @error('scope') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="category_id" class="form-label">Category</label>
                    <select name="category_id" id="category_id" class="form-select">
                        <option value="">Choose…</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('category_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="provider_id" class="form-label">Provider</label>
                    <select name="provider_id" id="provider_id" class="form-select">
                        <option value="">Choose…</option>
                        @foreach($providers as $provider)
                            <option value="{{ $provider->id }}" @selected(old('provider_id') == $provider->id)>
                                {{ $provider->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('provider_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="card mt-6">
            <div class="card-header">
                <h2 class="card-title">What to change</h2>
                <p class="card-description">Leave a field blank to leave it as it is on each rule.</p>
            </div>

            <div class="card-content grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="markup_percentage" class="form-label">Markup (%)</label>
                    <input type="number" step="0.01" min="0" max="1000" name="markup_percentage"
                           id="markup_percentage" class="form-input" value="{{ old('markup_percentage') }}">
                    @error('markup_percentage') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="markup_fixed" class="form-label">Fixed markup (₦)</label>
                    <input type="number" step="0.01" min="0" name="markup_fixed" id="markup_fixed"
                           class="form-input" value="{{ old('markup_fixed') }}">
                    @error('markup_fixed') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rounding_step" class="form-label">Round to nearest (₦)</label>
                    <input type="number" step="0.01" min="0" name="rounding_step" id="rounding_step"
                           class="form-input" value="{{ old('rounding_step') }}">
                    @error('rounding_step') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="card-content border-t">
                <label for="reason" class="form-label">Reason</label>
                <input type="text" name="reason" id="reason" class="form-input" required minlength="5" maxlength="500"
                       value="{{ old('reason') }}" placeholder="Recorded in the pricing audit log for every affected rule">
                @error('reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6 flex gap-2">
            <button type="submit" class="btn btn-primary">Apply to matching rules</button>
            <a href="{{ route('admin.pricing.rules') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
