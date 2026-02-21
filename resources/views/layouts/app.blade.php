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

<!-- SEO Meta Tags -->
    <meta name="description" content="{{ $siteSettings['meta_description'] ?? '' }}">
    <meta name="keywords" content="{{ $siteSettings['meta_keywords'] ?? '' }}">
    <!--END SEO-->
    
    <title>
        @yield('title')</title>
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
<body class="font-sans antialiased bg-green-50">

<!-- Announcements Modal - Shows only once per login session -->
@php
    use App\Models\PromotionNotification;
    
    // Get active announcements (notifications type)
    $activeAnnouncements = PromotionNotification::announcements()
        ->where(function($query) {
            $query->whereNull('starts_at')
                  ->orWhere('starts_at', '<=', now());
        })
        ->where(function($query) {
            $query->whereNull('ends_at')
                  ->orWhere('ends_at', '>=', now());
        })
        ->orderBy('created_at', 'desc')
        ->get();
    
    // Check if modal should be shown (only on specific pages, not all)
    $showAnnouncementModal = false;
    
    // Define which pages should show the modal
    $pagesToShowModal = ['dashboard', 'home', 'index'];
    $currentRoute = request()->route()->getName();
    
    // Check if current route is in the allowed list
    foreach($pagesToShowModal as $page) {
        if (strpos($currentRoute, $page) !== false) {
            $showAnnouncementModal = true;
            break;
        }
    }
    
    // Check if user has already seen announcements in this session
    $hasSeenAnnouncements = session('has_seen_announcements', false);
@endphp

@if($activeAnnouncements->count() > 0 && $showAnnouncementModal && !$hasSeenAnnouncements)
    <div id="announcementsModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4 animate-fadeIn">
        <div class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-2xl max-w-md w-full max-h-[80vh] overflow-hidden border border-gray-200 animate-slideUp">
            <!-- Modal Header -->
            <div class="relative">
                <div class="bg-gradient-to-r from-green-500 to-emerald-600 p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center backdrop-blur-sm">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-white">Latest Announcements</h3>
                        </div>
                        <button onclick="closeAnnouncements()" 
                                class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center hover:bg-white/30 transition-colors">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <!-- Decorative Element -->
                <div class="absolute -bottom-3 left-1/2 transform -translate-x-1/2">
                    <div class="w-6 h-6 bg-gradient-to-r from-green-500 to-emerald-600 rotate-45"></div>
                </div>
            </div>

            <!-- Modal Content -->
            <div class="p-6">
                <div class="space-y-4">
                    @foreach($activeAnnouncements as $announcement)
                    <div class="group bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl p-4 border border-green-100 hover:border-green-200 transition-all duration-300">
                        <!-- Badge if exists -->
                        @if($announcement->badge)
                            <span class="inline-block px-3 py-1 mb-3 text-xs font-bold rounded-full" 
                                  style="background-color: {{ $announcement->badge_color ?? '#10B981' }}; color: {{ $announcement->text_color ?? '#ffffff' }}">
                                {{ $announcement->badge }}
                            </span>
                        @endif
                        
                        <!-- Title and Icon -->
                        <div class="flex items-start gap-3">
                            @if($announcement->icon)
                                <div class="w-10 h-10 bg-gradient-to-br from-green-100 to-emerald-100 rounded-lg flex items-center justify-center flex-shrink-0 mt-1">
                                    <span class="text-lg">{{ $announcement->icon }}</span>
                                </div>
                            @endif
                            <div class="flex-1">
                                <h4 class="font-bold text-gray-900 mb-1">{{ $announcement->title }}</h4>
                                <p class="text-gray-600 text-sm leading-relaxed">{{ $announcement->content }}</p>
                                
                                <!-- Date if exists -->
                                @if($announcement->starts_at || $announcement->ends_at)
                                    <div class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        @if($announcement->starts_at && $announcement->ends_at)
                                            {{ $announcement->starts_at->format('M d') }} - {{ $announcement->ends_at->format('M d') }}
                                        @elseif($announcement->starts_at)
                                            Starts {{ $announcement->starts_at->format('M d') }}
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                
                <!-- Footer -->
                <div class="mt-8 pt-6 border-t border-gray-200">
                    <button onclick="closeAnnouncements()" 
                            class="w-full bg-gradient-to-r from-green-500 to-emerald-600 text-white font-semibold py-3 px-4 rounded-xl hover:from-green-600 hover:to-emerald-700 transition-all duration-300 transform hover:-translate-y-0.5 shadow-lg hover:shadow-xl flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Got it, thanks!
                    </button>
                    <p class="text-center text-xs text-gray-500 mt-4">
                        You can view these announcements anytime from your dashboard
                    </p>
                </div>
            </div>
        </div>
    </div>

    <style>
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes slideUp {
        from { 
            opacity: 0;
            transform: translateY(20px) scale(0.95);
        }
        to { 
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    
    .animate-fadeIn {
        animation: fadeIn 0.3s ease-out;
    }
    
    .animate-slideUp {
        animation: slideUp 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    </style>

    <script>
    function closeAnnouncements() {
        const modal = document.getElementById('announcementsModal');
        
        // Add fade out animation
        modal.style.animation = 'fadeOut 0.3s ease-in forwards';
        
        // Remove modal after animation
        setTimeout(() => {
            modal.style.display = 'none';
            
            // Send AJAX request to mark as viewed for this session
            fetch('/announcements/mark-session-viewed', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({})
            })
            .then(response => response.json())
            .then(data => {
                console.log('Announcements marked as viewed for this session');
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }, 300);
    }

    // Close modal on ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAnnouncements();
        }
    });

    // Close modal on backdrop click
    document.getElementById('announcementsModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAnnouncements();
        }
    });
    </script>
@endif
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