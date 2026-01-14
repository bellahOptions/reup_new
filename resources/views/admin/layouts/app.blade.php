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
    <style type="text/tailwindcss">
      @theme {
        --color-clifford: #da373d;
      }
      *{        
        font-family: "DM Sans", sans-serif;
      }
    </style>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <title>@yield('title') - Admin | {{ config('app.name') }}</title>
    
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet" />
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        .sidebar {
            width: 260px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar.collapsed {
            width: 80px;
        }
        .main-content {
            margin-left: 260px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .main-content.expanded {
            margin-left: 80px;
        }
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: -260px;
                z-index: 50;
                height: 100vh;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
        }
        .menu-item {
            transition: all 0.2s;
        }
        .menu-item:hover {
            transform: translateX(4px);
        }
    </style>
    @stack('styles')
</head>
<body class="font-sans antialiased bg-gray-50">
    <div class="min-h-screen flex" x-data="{ sidebarOpen: false, sidebarCollapsed: false }">
        <!-- Sidebar -->
        <aside 
            id="sidebar" 
            class="sidebar bg-gradient-to-b from-green-900 to-green-800 text-white fixed h-screen overflow-y-auto shadow-2xl"
            :class="{ 'collapsed': sidebarCollapsed, 'open': sidebarOpen }">
            <!-- Logo -->
            <div class="p-6 border-b border-gray-700/50">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-green-400 to-emerald-500 rounded-xl flex items-center justify-center shadow-lg">
                            <span class="text-xl">⚡</span>
                        </div>
                        <div x-show="!sidebarCollapsed" class="transition-all duration-300">
                            <h1 class="text-xl font-bold">{{ config('app.name') }}</h1>
                            <p class="text-xs text-gray-400">Admin Portal</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
<nav class="p-4 space-y-2">
    <!-- Dashboard -->
    <a href="{{ route('admin.dashboard') }}" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.dashboard') ? 'bg-green-500 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-700/50' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        <span x-show="!sidebarCollapsed" class="font-medium">Dashboard</span>
    </a>

    <!-- Bank Transfers -->
    <a href="{{ route('admin.bank-transfers.index') }}" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.bank-transfers.index*') ? 'bg-green-500 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-700/50' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
        </svg>
        <span x-show="!sidebarCollapsed" class="font-medium">Bank Transfers</span>
        @if(isset($pendingTransfers) && $pendingTransfers > 0)
            <span x-show="!sidebarCollapsed" class="ml-auto bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">{{ $pendingTransfers }}</span>
        @endif
    </a>

    <!-- Transactions -->
    <a href="{{ route('admin.transactions.index') }}" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.transactions*') ? 'bg-green-500 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-700/50' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
        </svg>
        <span x-show="!sidebarCollapsed" class="font-medium">Transactions</span>
    </a>

    <!-- Users -->
    <a href="{{ route('admin.users.index') }}" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.users*') ? 'bg-green-500 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-700/50' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
        </svg>
        <span x-show="!sidebarCollapsed" class="font-medium">Users</span>
    </a>

    <!-- Admins (Only for Super Admin) -->
    @if(auth()->user()->is_super_admin)
    <a href="{{ route('admin.admins.index') }}" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.admins*') ? 'bg-green-500 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-700/50' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span x-show="!sidebarCollapsed" class="font-medium">Admins</span>
    </a>
    @endif

    <!-- Live Chat -->
    @if(auth()->user()->hasPermission('chat'))
    <a href="{{ route('admin.chat.index') }}" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.chat*') ? 'bg-green-500 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-700/50' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>
        <span x-show="!sidebarCollapsed" class="font-medium">Live Chat</span>
        <span id="unreadChatCount" class="ml-auto bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full hidden"></span>
    </a>
    @endif

    <!-- Contact Messages -->
    <a href="{{ route('admin.contact.index') }}" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.contact*') ? 'bg-green-500 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-700/50' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
        <span x-show="!sidebarCollapsed" class="font-medium">Contact</span>
        <span id="unreadContactCount" class="ml-auto bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full hidden"></span>
    </a>

    <!-- Reports -->
    <a href="#" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.reports*') ? 'bg-green-500 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-700/50' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
        <span x-show="!sidebarCollapsed" class="font-medium">Reports</span>
    </a>

    <!-- Settings -->
    <a href="#" class="menu-item flex items-center space-x-3 px-4 py-3 rounded-xl {{ request()->routeIs('admin.settings*') ? 'bg-green-500 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-700/50' }}">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        <span x-show="!sidebarCollapsed" class="font-medium">Settings</span>
    </a>
</nav>

            <!-- Admin Info -->
            <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-700/50 bg-gray-900/50">
                <div class="flex items-center space-x-3 px-2">
                    <div class="w-10 h-10 bg-gradient-to-br from-green-400 to-emerald-500 rounded-full flex items-center justify-center text-white font-bold">
                        {{ substr(auth()->user()->name, 0, 1) }}
                    </div>
                    <div x-show="!sidebarCollapsed" class="flex-1 min-w-0">
                        <p class="text-sm font-semibold truncate">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-gray-400 truncate">Administrator</p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div id="main-content" class="main-content flex-1 flex flex-col min-h-screen" :class="{ 'expanded': sidebarCollapsed }">
            <!-- Top Navigation -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-40 shadow-sm">
                <div class="flex items-center justify-between px-6 py-4">
                    <div class="flex items-center space-x-4">
                        <!-- Mobile Menu Button -->
                        <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden text-gray-600 hover:text-gray-900">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>

                        <!-- Desktop Toggle -->
                        <button @click="sidebarCollapsed = !sidebarCollapsed" class="hidden lg:block text-gray-600 hover:text-gray-900">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>

                        <!-- Page Title -->
                        <h1 class="text-2xl font-bold text-gray-900">@yield('page-title', 'Dashboard')</h1>
                    </div>

                    <div class="flex items-center space-x-4">
                        <!-- Notifications -->
                        <!-- Notifications -->
<div class="relative" id="notificationContainer">
    <button type="button" 
            id="notificationBell"
            class="relative p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        
        {{-- Notification Badge --}}
        <span id="notificationBadge" 
              class="absolute top-0 right-0 hidden bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">
            0
        </span>
    </button>
    
    {{-- Notification Dropdown --}}
    <div id="notificationDropdown" 
         class="hidden absolute right-0 mt-2 w-96 bg-white rounded-xl shadow-2xl border border-gray-200 z-50 max-h-[32rem] overflow-hidden">
        
        {{-- Dropdown Header --}}
        <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between bg-gradient-to-r from-green-50 to-green-50">
            <h3 class="font-bold text-gray-900 flex items-center">
                <span class="text-xl mr-2">🔔</span>
                Notifications
            </h3>
            <button onclick="markAllAsRead()" 
                    class="text-xs text-green-600 hover:text-green-800 font-semibold">
                Mark all read
            </button>
        </div>
        
        {{-- Quick Stats --}}
        <div class="px-4 py-2 bg-gray-50 border-b border-gray-200 grid grid-cols-2 gap-4 text-xs">
            <div class="flex items-center justify-between">
                <span class="text-gray-600">💬 Chats</span>
                <span id="chatCount" class="font-bold text-green-600">0</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-gray-600">📧 Contacts</span>
                <span id="contactCount" class="font-bold text-green-600">0</span>
            </div>
        </div>
        
        {{-- Notification List --}}
        <div id="notificationList" class="overflow-y-auto max-h-96">
            {{-- Notifications will be loaded here dynamically --}}
            <div class="p-8 text-center">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-green-600"></div>
                <p class="text-gray-600 mt-2 text-sm">Loading notifications...</p>
            </div>
        </div>
        
        {{-- Dropdown Footer --}}
        <div class="px-4 py-3 border-t border-gray-200 bg-gray-50 flex justify-between items-center">
            <a href="{{ route('admin.chat.index') }}" 
               class="text-xs text-green-600 hover:text-green-800 font-semibold flex items-center">
                <span class="mr-1">💬</span>
                View All Chats
            </a>
            <a href="{{ route('admin.contact.index') }}" 
               class="text-xs text-green-600 hover:text-green-800 font-semibold flex items-center">
                <span class="mr-1">📧</span>
                View All Messages
            </a>
        </div>
    </div>
</div>

                        <!-- Profile Dropdown -->
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" class="flex items-center space-x-3 p-2 rounded-lg hover:bg-gray-100 transition-colors">
                                <div class="w-9 h-9 bg-gradient-to-br from-green-400 to-emerald-500 rounded-full flex items-center justify-center text-white font-bold">
                                    {{ substr(auth()->user()->name, 0, 1) }}
                                </div>
                                <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-lg border border-gray-200 py-2">
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    Profile Settings
                                </a>
                                <form method="POST" action="{{ route('admin.logout') }}">
                                    @csrf
                                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                        Logout
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 p-6">
                <!-- Alerts -->
                @if(session('success'))
                    <div class="mb-6 bg-green-50 border-l-4 border-green-500 rounded-lg p-4 alert-dismissible">
                        <div class="flex items-start">
                            <svg class="w-5 h-5 text-green-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <p class="ml-3 text-sm font-medium text-green-800">{{ session('success') }}</p>
                        </div>
                    </div>
                @endif

                @if(session('error'))
                    <div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4 alert-dismissible">
                        <div class="flex items-start">
                            <svg class="w-5 h-5 text-red-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            <p class="ml-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
                        </div>
                    </div>
                @endif

                @yield('content')
            </main>

            <!-- Footer -->
            <footer class="bg-white border-t border-gray-200 py-4 px-6">
                <div class="flex flex-col md:flex-row justify-between items-center text-sm text-gray-600">
                    <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
                    <p>Admin Dashboard v1.0 - by Bellah Options BN3668420</p>
                </div>
            </footer>
        </div>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div 
        x-show="sidebarOpen" 
        @click="sidebarOpen = false" 
        class="fixed inset-0 bg-black/50 z-40 lg:hidden"
        x-transition:enter="transition-opacity ease-linear duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-linear duration-300"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    ></div>

    <script>
        // Auto-dismiss alerts
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.3s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>
    
    @stack('scripts')
    <script>
        class NotificationSystem {
    constructor() {
        this.pollInterval = null;
        this.notificationBell = document.getElementById('notificationBell');
        this.notificationBadge = document.getElementById('notificationBadge');
        this.notificationDropdown = document.getElementById('notificationDropdown');
        this.notificationList = document.getElementById('notificationList');
        this.lastCheck = Date.now();
        this.soundEnabled = localStorage.getItem('notificationSound') !== 'false';
        
        this.init();
    }
    
    init() {
        // Start polling for notifications
        this.startPolling();
        
        // Set up click handlers
        if (this.notificationBell) {
            this.notificationBell.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleDropdown();
            });
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (this.notificationDropdown && !this.notificationDropdown.contains(e.target)) {
                this.notificationDropdown.classList.add('hidden');
            }
        });
        
        // Initial load
        this.loadNotifications();
    }
    
    startPolling() {
        // Poll every 5 seconds
        this.pollInterval = setInterval(() => {
            this.loadNotifications();
        }, 5000);
    }
    
    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
        }
    }
    
    async loadNotifications() {
        try {
            const response = await fetch('/admin/notifications/unread', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) throw new Error('Failed to load notifications');
            
            const data = await response.json();
            this.updateUI(data);
            
            // Play sound if there are new notifications
            if (data.total_unread > 0 && this.shouldPlaySound(data)) {
                this.playNotificationSound();
            }
            
        } catch (error) {
            console.error('Error loading notifications:', error);
        }
    }
    
    updateUI(data) {
        // Update badge count
        if (this.notificationBadge) {
            if (data.total_unread > 0) {
                this.notificationBadge.textContent = data.total_unread > 99 ? '99+' : data.total_unread;
                this.notificationBadge.classList.remove('hidden');
                
                // Pulse animation for new notifications
                this.notificationBell?.classList.add('animate-pulse');
                setTimeout(() => {
                    this.notificationBell?.classList.remove('animate-pulse');
                }, 2000);
            } else {
                this.notificationBadge.classList.add('hidden');
            }
        }
        
        // Update notification list
        if (this.notificationList && data.notifications) {
            this.renderNotifications(data.notifications);
        }
    }
    
    renderNotifications(notifications) {
        if (notifications.length === 0) {
            this.notificationList.innerHTML = `
                <div class="p-8 text-center">
                    <span class="text-4xl mb-2 block">🔔</span>
                    <p class="text-gray-600 text-sm">No new notifications</p>
                </div>
            `;
            return;
        }
        
        this.notificationList.innerHTML = notifications.map(notification => `
            <a href="${notification.url}" 
               class="block px-4 py-3 hover:bg-gray-50 transition-colors border-b border-gray-100"
               onclick="markAsRead('${notification.type}', '${notification.id}')">
                <div class="flex items-start space-x-3">
                    <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br ${this.getGradientColor(notification.type)} rounded-lg flex items-center justify-center">
                        <span class="text-lg">${notification.icon}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate">
                            ${notification.title}
                        </p>
                        <p class="text-xs text-gray-600 mt-1 line-clamp-2">
                            ${notification.message}
                        </p>
                        <p class="text-xs text-gray-500 mt-1">
                            ${notification.time}
                        </p>
                    </div>
                </div>
            </a>
        `).join('');
    }
    
    getGradientColor(type) {
        switch(type) {
            case 'chat':
                return 'from-green-400 to-green-500';
            case 'contact':
                return 'from-green-400 to-green-500';
            default:
                return 'from-gray-400 to-gray-500';
        }
    }
    
    toggleDropdown() {
        if (this.notificationDropdown) {
            this.notificationDropdown.classList.toggle('hidden');
        }
    }
    
    shouldPlaySound(data) {
        // Only play sound if sound is enabled and there are new notifications
        // since last check
        return this.soundEnabled && data.total_unread > 0;
    }
    
    playNotificationSound() {
        // Create a simple notification sound using Web Audio API
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.5);
        } catch (error) {
            console.error('Error playing notification sound:', error);
        }
    }
}

// Mark notification as read
async function markAsRead(type, id) {
    try {
        await fetch('/admin/notifications/mark-read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ type, id })
        });
    } catch (error) {
        console.error('Error marking notification as read:', error);
    }
}

// Mark all notifications as read
async function markAllAsRead() {
    try {
        const response = await fetch('/admin/notifications/mark-read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (response.ok) {
            // Reload notifications
            window.notificationSystem?.loadNotifications();
        }
    } catch (error) {
        console.error('Error marking all as read:', error);
    }
}

// Initialize notification system when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.notificationSystem = new NotificationSystem();
});

// Clean up on page unload
window.addEventListener('beforeunload', () => {
    if (window.notificationSystem) {
        window.notificationSystem.stopPolling();
    }
});
    </script>

    @push('scripts')
<script src="{{ asset('js/notifications.js') }}"></script>
<script>
// Update notification counts in the dropdown
function updateNotificationCounts(data) {
    if (data.unread_chats !== undefined) {
        document.getElementById('chatCount').textContent = data.unread_chats;
    }
    if (data.unread_contacts !== undefined) {
        document.getElementById('contactCount').textContent = data.unread_contacts;
    }
}

// Override the updateUI method to include counts
if (window.notificationSystem) {
    const originalUpdateUI = window.notificationSystem.updateUI;
    window.notificationSystem.updateUI = function(data) {
        originalUpdateUI.call(this, data);
        updateNotificationCounts(data);
    };
}

// Update admin activity every minute
setInterval(() => {
    fetch('/admin/update-activity', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'X-Requested-With': 'XMLHttpRequest'
        }
    });
}, 60000);
</script>
@endpush
</body>
</html>