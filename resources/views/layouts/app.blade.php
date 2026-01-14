<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!--Icon Links-->
    <link rel="shortcut icon" href="{{ asset('images/reup-icon-06.jpg')}}" type="image/x-icon">
    <link rel="apple-touch-icon" sizes="57x57" href="{{ asset('images/apple-icon-57x57.png')}}">
<link rel="apple-touch-icon" sizes="60x60" href="{{ asset('images/apple-icon-60x60.png')}}">
<link rel="apple-touch-icon" sizes="72x72" href="{{ asset('images/apple-icon-72x72.png')}}">
<link rel="apple-touch-icon" sizes="76x76" href="{{ asset('images/apple-icon-76x76.png')}}">
<link rel="apple-touch-icon" sizes="114x114" href="{{ asset('images/apple-icon-114x114.png')}}">
<link rel="apple-touch-icon" sizes="120x120" href="{{ asset('images/apple-icon-120x120.png')}}">
<link rel="apple-touch-icon" sizes="144x144" href="{{ asset('images/apple-icon-144x144.png')}}">
<link rel="apple-touch-icon" sizes="152x152" href="{{ asset('images/apple-icon-152x152.png')}}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-icon-180x180.png')}}">
<link rel="icon" type="image/png" sizes="192x192"  href="{{ asset('images/android-icon-192x192.png')}}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png')}}">
<link rel="icon" type="image/png" sizes="96x96" href="{{ asset('images/favicon-96x96.png')}}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/favicon-16x16.png')}}">
<link rel="manifest" href="/images/manifest.json">
<meta name="msapplication-TileColor" content="#2AB70D">
<meta name="msapplication-TileImage" content="{{ asset('images/ms-icon-144x144.png')}}">
<meta name="theme-color" content="#2AB70D">
<!--End Icon Links-->   
    <title>Reup | Buy airtime, data, and pay bills instantly with ReUp. Fast, secure, and stress-free — anytime, anywhere.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style type="text/tailwindcss">
      @theme {
        --color-clifford: #da373d;
      }
      *{        
        font-family: "DM Sans", sans-serif;
      }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

    {{-- SweetAlert2 --}}
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

{{-- Remove or comment out any existing auto-show scripts for success/error to avoid duplicates --}}
{{--
@if(session('success'))
<script>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: '{{ session('success') }}',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
</script>
@endif

@if(session('error'))
<script>
    Swal.fire({
        icon: 'error',
        title: 'Error!',
        text: '{{ session('error') }}',
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true
    });
</script>
@endif
--}}
</head>

<!-- Announcements Modal -->
@if(!empty($announcements) && !session('announcements_viewed'))
    <div id="announcementsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full max-h-[80vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-gray-900">📢 Latest Announcements</h3>
                    <button onclick="closeAnnouncements()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                
                <div class="space-y-4">
                    @foreach($announcements as $announcement)
                    <div class="bg-green-50 p-4 rounded-lg">
                        <div class="flex items-start space-x-3">
                            @if($announcement['icon'])
                                <span class="text-2xl">{{ $announcement['icon'] }}</span>
                            @endif
                            <div>
                                <h4 class="font-semibold text-green-800 mb-1">{{ $announcement['title'] }}</h4>
                                <p class="text-gray-700 text-sm">{{ $announcement['content'] }}</p>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                
                <div class="mt-6 flex justify-between items-center">
                    <label class="flex items-center space-x-2 text-sm text-gray-600">
                        <input type="checkbox" id="dontShowAgain" class="rounded border-gray-300 text-green-500 focus:ring-green-500">
                        <span>Don't show again for 7 days</span>
                    </label>
                    <button onclick="closeAnnouncements()" 
                            class="bg-gradient-to-r from-green-500 to-emerald-600 text-white font-semibold py-2 px-6 rounded-lg hover:from-green-600 hover:to-emerald-700 transition-all duration-200">
                        Got it!
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    function closeAnnouncements() {
        const dontShow = document.getElementById('dontShowAgain').checked;
        
        if (dontShow) {
            // Set cookie for 7 days
            document.cookie = "announcements_viewed=true; max-age=" + (7 * 24 * 60 * 60) + "; path=/";
        } else {
            // Set session only
            fetch('{{ route("announcements.viewed") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json'
                }
            });
        }
        
        document.getElementById('announcementsModal').style.display = 'none';
    }
    </script>
@endif
<body class="font-sans antialiased bg-green-50">
    @if(auth()->check() && auth()->user()->requires_phone_update)
    @if(!request()->is('profile*'))
        <div class="bg-gradient-to-r from-red-50 to-pink-50 border-b border-red-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <span class="text-red-500 mr-2">📱</span>
                        <span class="text-sm text-red-700">
                            <strong>Action Required:</strong> Add your phone number for better service
                        </span>
                    </div>
                    <a href="{{ route('profile.index') }}" 
                       class="text-sm bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition-all duration-200">
                        Update Now
                    </a>
                </div>
            </div>
        </div>
    @endif
@endif
    <!-- Elfsight WhatsApp Chat | ReuP -->
<script src="https://elfsightcdn.com/platform.js" async></script>
<div class="elfsight-app-b32271e7-82bd-46f3-80c8-f7de88dca9b0" data-elfsight-app-lazy></div>
      @include('layouts.navigation')
   @yield('content')

    <!-- Footer (optional) -->
  @include('layouts.footer')

    <!-- JavaScript for interactive elements -->
    <script>
        function toggleMoreOptions() {
            const menu = document.getElementById('more-options-menu');
            menu.classList.toggle('hidden');
            
            // Close menu when clicking outside
            document.addEventListener('click', function closeMenu(e) {
                if (!menu.contains(e.target) && e.target.id !== 'more-options-button') {
                    menu.classList.add('hidden');
                    document.removeEventListener('click', closeMenu);
                }
            });
        }

        function toggleUserMenu() {
            const menu = document.getElementById('user-menu');
            menu.classList.toggle('hidden');
            
            // Close menu when clicking outside
            document.addEventListener('click', function closeMenu(e) {
                if (!menu.contains(e.target) && e.target.id !== 'user-menu-button') {
                    menu.classList.add('hidden');
                    document.removeEventListener('click', closeMenu);
                }
            });
        }

        function toggleMobileMenu() {
            const mobileMenu = document.getElementById('mobile-menu');
            const menuIcon = document.getElementById('menu-icon');
            const closeIcon = document.getElementById('close-icon');
            
            mobileMenu.classList.toggle('hidden');
            menuIcon.classList.toggle('hidden');
            closeIcon.classList.toggle('hidden');
            
            // Update aria-expanded
            const button = document.getElementById('mobile-menu-button');
            const isExpanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', !isExpanded);
        }
        
        // Close all dropdowns when clicking anywhere
        document.addEventListener('click', function(event) {
            if (!event.target.matches('#more-options-button') && !event.target.closest('#more-options-menu')) {
                const moreOptionsMenu = document.getElementById('more-options-menu');
                if (moreOptionsMenu && !moreOptionsMenu.classList.contains('hidden')) {
                    moreOptionsMenu.classList.add('hidden');
                }
            }
            
            if (!event.target.matches('#user-menu-button') && !event.target.closest('#user-menu')) {
                const userMenu = document.getElementById('user-menu');
                if (userMenu && !userMenu.classList.contains('hidden')) {
                    userMenu.classList.add('hidden');
                }
            }
        });
    </script>
</body>
</html>