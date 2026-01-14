<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ config('app.name') }}</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap" rel="stylesheet">
    
    <!-- Favicon -->
    <link rel="shortcut icon" href="{{ asset('images/reup-icon-06.jpg')}}" type="image/x-icon">
    
    <style>
        * {
            font-family: "DM Sans", sans-serif;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #f0fdf4 100%);
            min-height: 100vh;
        }
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        .animate-pulse-slow {
            animation: pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body class="antialiased">
    <!-- Background Decoration -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -right-40 w-80 h-80 bg-green-200 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-float"></div>
        <div class="absolute -bottom-40 -left-40 w-80 h-80 bg-emerald-200 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-float" style="animation-delay: -2s;"></div>
        <div class="absolute top-1/2 left-1/4 w-60 h-60 bg-teal-200 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-float" style="animation-delay: -4s;"></div>
    </div>

    <!-- Main Content -->
    <main class="relative min-h-screen flex flex-col items-center justify-center px-4 py-12">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="relative pb-6 text-center">
        <div class="max-w-7xl mx-auto px-4">
            <p class="text-gray-600 text-sm">
                © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
            </p>
            <div class="mt-2 flex items-center justify-center space-x-4 text-xs text-gray-500">
                <a href="{{ url('/') }}" class="hover:text-green-600 transition-colors">Home</a>
                <span>•</span>
                <a href="{{ route('contact') }}" class="hover:text-green-600 transition-colors">Support</a>
                <span>•</span>
                <a href="{{ route('privacy-policy') }}" class="hover:text-green-600 transition-colors">Privacy</a>
                <span>•</span>
                <a href="{{ route('terms-of-service') }}" class="hover:text-green-600 transition-colors">Terms</a>
            </div>
        </div>
    </footer>
</body>
</html>