@extends('layouts.main')

@section('title', 'Data Plans')

@section('main')
<div class="min-h-screen md:w-200 md:mx-auto bg-gray-50">
    <!-- Fixed Header with Sort -->
    <div class="sticky top-0 z-10 bg-white border-b shadow-sm p-3">
        <div class="flex items-center justify-between mb-2">
            <h1 class="text-lg font-bold">Data Plans</h1>
            <div class="flex gap-2">
                <select id="sortSelect" class="border rounded px-2 py-1 text-sm">
                    <option value="default">Sort By</option>
                    <option value="price-low">Price: Low to High</option>
                    <option value="price-high">Price: High to Low</option>
                </select>
                <a href="{{ route('pricelist.refresh') }}" class="px-2 py-1 bg-green-600 text-white rounded text-sm">Refresh</a>
            </div>
        </div>
        
        <div class="flex gap-2 overflow-x-auto pb-1">
            <button class="network-filter px-3 py-1 bg-green-600 text-white rounded-full text-sm" data-network="all">All</button>
            @foreach(['MTN', 'AIRTEL', 'GLO', '9MOBILE'] as $network)
                <button class="network-filter px-3 py-1 bg-gray-100 rounded-full text-sm" data-network="{{ $network }}">{{ $network }}</button>
            @endforeach
        </div>
    </div>

    <!-- Plans List -->
    <div class="p-3 space-y-2" id="plansContainer">
        @foreach($data['data_plans'] as $plan)
        <div class="plan-card bg-white rounded-lg shadow p-3" 
             data-network="{{ $plan['network'] }}"
             data-price="{{ $plan['your_price'] }}">
            <div class="flex justify-between">
                <div>
                    <div class="flex items-center mb-1">
                        <div class="w-6 h-6 rounded-sm mr-2 flex items-center justify-center 
                            @if($plan['network'] == 'MTN') bg-yellow-500
                            @elseif($plan['network'] == 'AIRTEL') bg-red-500
                            @else bg-green-500 @endif">
                            <span class="text-white text-xs">{{ substr($plan['network'], 0, 1) }}</span>
                        </div>
                        <span class="font-bold text-sm">{{ $plan['network'] }}</span>
                        <span class="ml-2 text-xs bg-gray-100 px-2 py-0.5 rounded">{{ $plan['validity'] }}</span>
                    </div>
                    <p class="text-sm">{{ $plan['plan_name'] }}</p>
                    <p class="text-xs text-gray-500">{{ $plan['data_volume'] }}</p>
                </div>
                <div class="text-right">
                    <div class="text-md font-bold text-green-600">₦{{ number_format($plan['your_price'], 2) }}</div>
                    @auth
                    <a href="{{ route('airtime-data.index') }}" class="mt-1 px-2 py-0.5 bg-green-600 text-white rounded text-xs">Buy</a>
                    @endauth
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

<script>
// Simple filtering and sorting
const plansContainer = document.getElementById('plansContainer');
const networkFilters = document.querySelectorAll('.network-filter');
const sortSelect = document.getElementById('sortSelect');

// Filter by network
networkFilters.forEach(btn => {
    btn.onclick = function() {
        const network = this.dataset.network;
        
        // Update active button
        networkFilters.forEach(b => {
            b.classList.remove('bg-green-600', 'text-white');
            b.classList.add('bg-gray-100');
        });
        this.classList.add('bg-green-600', 'text-white');
        
        // Filter plans
        document.querySelectorAll('.plan-card').forEach(card => {
            if (network === 'all' || card.dataset.network === network) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    };
});

// Sort plans
sortSelect.onchange = function() {
    const cards = Array.from(document.querySelectorAll('.plan-card'));
    
    cards.sort((a, b) => {
        const priceA = parseFloat(a.dataset.price);
        const priceB = parseFloat(b.dataset.price);
        
        if (this.value === 'price-low') return priceA - priceB;
        if (this.value === 'price-high') return priceB - priceA;
        return 0;
    });
    
    // Reorder DOM
    cards.forEach(card => plansContainer.appendChild(card));
};
</script>
@endsection