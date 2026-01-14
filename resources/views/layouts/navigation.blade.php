 <!-- Main Container -->
    <div class="min-h-screen">
        <!-- Navigation -->
        <nav class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <!-- Left side: Logo and Navigation -->
                    <div class="flex items-center">
                        <!-- Logo -->
                        <div class="flex-shrink-0">
                            <a href="{{ route('dashboard') }}" class="flex items-center">
                                <img src="{{ asset('images/reup-03.svg') }}" alt="ReUp Logo" class="h-8 w-auto">
                            </a>
                        </div>

                        <!-- Desktop Navigation Links -->
                        <div class="hidden md:ml-6 md:flex md:space-x-4">
                            <a href="{{ route('dashboard') }}" 
                               class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('dashboard') ? 'bg-gray-100 text-gray-900' : 'text-gray-700 hover:text-gray-900 hover:bg-gray-50' }}">
                                Dashboard
                            </a>
                            <a href="{{     route('airtime-data.index') }}" 
                               class="px-3 py-2 rounded-md text-sm font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                                Buy Airtime/Data
                            </a>
                            
                            <!-- More Options Dropdown -->
                            <div class="relative">
                                <button type="button" 
                                        id="more-options-button"
                                        class="flex items-center px-3 py-2 rounded-md text-sm font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50 focus:outline-none"
                                        aria-expanded="false" 
                                        aria-haspopup="true"
                                        onclick="toggleMoreOptions()">
                                    More Options
                                    <svg class="ml-1 h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                                
                                <!-- More Options Dropdown Menu -->
                                <div id="more-options-menu" 
                                     class="hidden origin-top-left absolute left-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-20">
                                    <div class="py-1" role="menu" aria-orientation="vertical">
                                        <a href="#" class="block px-4 py-3 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 border-b border-gray-100">
                                            <div class="font-medium">CableTV Subscription</div>
                                        </a>
                                        <a href="#" class="block px-4 py-3 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 border-b border-gray-100">
                                            <div class="font-medium">Electricity Payment</div>
                                        </a>
                                        <a href="#" class="block px-4 py-3 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 border-b border-gray-100">
                                            <div class="font-medium">Print Recharge Card</div>
                                        </a>
                                        <a href="#" class="block px-4 py-3 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 border-b border-gray-100">
                                            <div class="font-medium">Fund Betting Wallet</div>
                                        </a>
                                        <a href="#" class="muted block px-4 py-3 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 border-b border-gray-100">
                                            <div class="font-medium">Purchase WAEC e-PIN</div>
                                        </a>
                                        <a href="#" class="muted block px-4 py-3 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900">
                                            <div class="font-medium">Purchase JAMB e-PIN</div>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <a href="#" 
                               class="px-3 py-2 rounded-md text-sm bg-green-100 font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                                Fund Wallet
                            </a>
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
                    </div>
                        
                    <!-- Right side: User dropdown -->
                     @auth
                    <div class="hidden md:flex md:items-center md:space-x-4">
                        <div class="relative">
                            <button type="button" 
                                    id="user-menu-button" 
                                    class="flex items-center space-x-2 text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                                    aria-expanded="false" 
                                    aria-haspopup="true"
                                    onclick="toggleUserMenu()">
                                    
                                <span class="text-gray-700 font-medium">{{ Auth::user()->name }}</span>
                                
                                <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <!-- Dropdown menu -->
                            <div id="user-menu" 
                                 class="hidden origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-10">
                                 <a href="{{ route('profile.index') }}" 
                               class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                Profile
                            </a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" 
                                            class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        Log Out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>@endauth
                    

                    <!-- Mobile menu button -->
                    <div class="md:hidden flex items-center">
                        <button type="button" 
                                id="mobile-menu-button"
                                class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"
                                aria-controls="mobile-menu" 
                                aria-expanded="false"
                                onclick="toggleMobileMenu()">
                            <span class="sr-only">Open main menu</span>
                            <!-- Hamburger icon -->
                            <svg id="menu-icon" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <!-- Close icon (hidden by default) -->
                            <svg id="close-icon" class="hidden h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile menu -->
            <div id="mobile-menu" class="hidden md:hidden">
                <div class="px-2 pt-2 pb-3 space-y-1">
                    <a href="{{ route('dashboard') }}" 
                       class="block px-3 py-2 rounded-md text-base font-medium {{ request()->routeIs('dashboard') ? 'bg-gray-100 text-gray-900' : 'text-gray-700 hover:text-gray-900 hover:bg-gray-50' }}">
                        Dashboard
                    </a>
                    <a href="#" 
                       class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                        Buy Airtime/Data
                    </a>
                    <a href="#" 
                       class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                        Pay Cable TV Bill
                    </a>
                    
                    <!-- Mobile More Options Links -->
                    <div class="pl-4 pt-2 pb-1 border-l-2 border-gray-200 ml-3">
                        <div class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-2">More Options</div>
                        <a href="#" class="block px-3 py-2 rounded-md text-sm text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                            CableTV Subscription
                        </a>
                        <a href="#" class="block px-3 py-2 rounded-md text-sm text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                            Electricity Payment
                        </a>
                        <a href="#" class="block px-3 py-2 rounded-md text-sm text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                            Print Recharge Card
                        </a>
                        <a href="#" class="block px-3 py-2 rounded-md text-sm text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                            Fund Betting Wallet
                        </a>
                        <a href="#" class="block px-3 py-2 rounded-md text-sm text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                            Purchase WAEC e-PIN
                        </a>
                        <a href="#" class="block px-3 py-2 rounded-md text-sm text-gray-700 hover:text-gray-900 hover:bg-gray-50">
                            Purchase JAMB e-PIN
                        </a>
                    </div>
                    
                    <a href="#" 
                       class="block px-3 py-2 rounded-md text-base font-medium bg-green-100 text-gray-700 hover:text-gray-900 hover:bg-green-200">
                        Fund Wallet
                    </a>
                </div>
                
                <div class="pt-4 pb-3 border-t border-gray-200">
                    <div class="flex items-center px-4">
                        <div id="mobile-user-info"> @auth
                            <div class="text-base font-medium text-gray-800">{{ Auth::user()->name }}</div>
                            <div class="text-sm font-medium text-gray-500">{{ Auth::user()->email }}</div>
                            @endauth
                        </div>
                    </div>
                    <div class="mt-3 space-y-1" id="mobile-user-menu">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" 
                                    class="block w-full text-left px-4 py-2 text-base font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-100">
                                Log Out
                            </button>
                        </form>
                    </div>
                    @auth
    @if(Auth::user()->isAdmin())
        <a href="{{ route('admin.dashboard') }}" class="text-gray-700 hover:text-green-600 px-3 py-2 text-sm font-medium">
            Admin Dashboard
        </a>
    @endif
@endauth

                </div>
                
            </div>
            
        </nav>
        <script>
            $(document).ready(function() {
                // Toggle More Options Menu
                window.toggleMoreOptions = function() {
                    $('#more-options-menu').toggle();
                };

                // Toggle User Menu
                window.toggleUserMenu = function() {
                    $('#user-menu').toggle();
                };

                // Toggle Mobile Menu
                window.toggleMobileMenu = function() {
                    $('#mobile-menu').toggle();
                    $('#menu-icon').toggle();
                    $('#close-icon').toggle();
                };

                // Close menus when clicking outside
                $(document).click(function(event) {
                    var target = $(event.target);
                    if (!target.closest('#more-options-button').length && !target.closest('#more-options-menu').length) {
                        $('#more-options-menu').hide();
                    }
                    if (!target.closest('#user-menu-button').length && !target.closest('#user-menu').length) {
                        $('#user-menu').hide();
                    }
                });
            });
        </script>
        <!-- End Navigation -->