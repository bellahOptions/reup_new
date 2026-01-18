<header class="w-full py-4 px-4 sm:px-6 lg:px-8">
    <nav class="relative bg-white border border-gray-200/60 rounded-2xl shadow-sm hover:shadow-md transition-shadow duration-300 w-full max-w-7xl mx-auto">
        <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <!-- Logo -->
                <div class="flex items-center">
                    <a href="/" class="flex items-center space-x-2">
                        <img src="{{ asset('images/reup-03.svg') }}" alt="Reup Logo" class="h-6 w-auto sm:h-7" />
                    </a>
                </div>

                <!-- Desktop Navigation -->
                <div class="hidden md:flex md:items-center md:space-x-1">
                    <a href="/" 
                       class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-white bg-green-600 hover:bg-green-700 transition-colors duration-200">
                        Home
                    </a>
                    <a href="{{ route('airtime-data.index') }}" 
                       class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-green-600 hover:bg-green-50 transition-colors duration-200">
                        Buy Airtime
                    </a>
                    <a href="{{ route('airtime-data.index') }}"  
                       class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-green-600 hover:bg-green-50 transition-colors duration-200">
                        Buy Data
                    </a>
                    <a href="{{ route('contact') }}" 
                       class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-green-600 hover:bg-green-50 transition-colors duration-200">
                        Contact
                    </a>
                </div>

                <!-- CTA Buttons -->
                <div class="hidden md:flex md:items-center md:space-x-3">
                    @auth
                    @if(Auth::user() && !Auth::user()->isAdmin())
    {{-- The user is logged in... display content for authenticated users --}}
    <a href="{{ url('/dashboard') }}" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-green-600 hover:bg-green-50 transition-colors duration-200">You're logged in as {{ Auth::user()->name }}</a>

    @elseif(Auth::user()->isAdmin())
        <a href="{{ route('admin.dashboard') }}" class="text-gray-700 hover:bg-green-100 hover:text-green-600 px-3 py-2 text-sm font-medium">
            Admin Dashboard
        </a>
    @endif
    @endauth
@guest
                    <a href="{{ route('login') }}" 
                       class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-green-600 hover:bg-gray-50 transition-colors duration-200">
                        Sign In
                    </a>
                    <a href="{{ route('register') }}" 
                       class="inline-flex items-center px-5 py-2 rounded-lg text-sm font-medium text-white bg-green-600 hover:bg-green-700 shadow-sm hover:shadow transition-all duration-200">
                        Sign Up
                    </a>
                    @endguest
                </div>

                <!-- Mobile menu button -->
                <div class="flex md:hidden">
                    <button type="button" 
                            id="mobile-menu-button"
                            class="inline-flex items-center justify-center p-2 rounded-lg text-gray-700 hover:text-green-600 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-500 transition-colors duration-200"
                            aria-controls="mobile-menu" 
                            aria-expanded="false">
                        <span class="sr-only">Open main menu</span>
                        <!-- Hamburger icon -->
                        <svg class="block h-6 w-6" id="menu-open-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                        <!-- Close icon -->
                        <svg class="hidden h-6 w-6" id="menu-close-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile menu -->
        <div class="hidden md:hidden" id="mobile-menu">
            <div class="border-t border-gray-200/60 px-4 pt-4 pb-4 space-y-1">
                <a href="/" 
                   class="block px-4 py-3 rounded-lg text-base font-medium text-white bg-green-600 hover:bg-green-700 transition-colors duration-200">
                    Home
                </a>
                <a href="{{ route('airtime-data.index') }}" 
                   class="block px-4 py-3 rounded-lg text-base font-medium text-gray-700 hover:text-green-600 hover:bg-green-50 transition-colors duration-200">
                    Buy Airtime
                </a>
                <a href="{{ route('airtime-data.index') }}" 
                   class="block px-4 py-3 rounded-lg text-base font-medium text-gray-700 hover:text-green-600 hover:bg-green-50 transition-colors duration-200">
                    Buy Data
                </a>
                <a href="{{ route('contact') }}" 
                   class="block px-4 py-3 rounded-lg text-base font-medium text-gray-700 hover:text-green-600 hover:bg-green-50 transition-colors duration-200">
                    Contact
                </a>
                
                <!-- Mobile CTA Buttons -->
                <div class="pt-4 space-y-2 border-t border-gray-200/60">
                    <a href="{{ route('login') }}" 
                       class="block w-full px-4 py-3 text-center rounded-lg text-base font-medium text-gray-700 border border-gray-300 hover:border-green-600 hover:text-green-600 transition-colors duration-200">
                        Sign In
                    </a>
                    <a href="{{route('register')}}" 
                       class="block w-full px-4 py-3 text-center rounded-lg text-base font-medium text-white bg-green-600 hover:bg-green-700 shadow-sm transition-colors duration-200">
                        Sign Up
                    </a>
                </div>
            </div>
        </div>
    </nav>
</header>

<script>
    // Mobile menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const menuOpenIcon = document.getElementById('menu-open-icon');
        const menuCloseIcon = document.getElementById('menu-close-icon');

        if (mobileMenuButton && mobileMenu) {
            mobileMenuButton.addEventListener('click', function() {
                const isExpanded = mobileMenuButton.getAttribute('aria-expanded') === 'true';
                
                mobileMenuButton.setAttribute('aria-expanded', !isExpanded);
                mobileMenu.classList.toggle('hidden');
                menuOpenIcon.classList.toggle('hidden');
                menuCloseIcon.classList.toggle('hidden');
            });
        }
    });
</script>