<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="shortcut icon" href="{{ asset('images/reup-icon-06.jpg')}}" type="image/x-icon">
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
    <style>
        .login-gradient {
            background: linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%);
        }
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        .pulse-slow {
            animation: pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
    </style>
</head>
<body class="font-sans antialiased">
    <div class="min-h-screen flex">
        <!-- Left Side - Branding -->
        <div class="hidden lg:flex lg:w-1/2 login-gradient relative overflow-hidden">
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxnIGZpbGw9IiNmZmZmZmYiIGZpbGwtb3BhY2l0eT0iMC4wNSI+PHBhdGggZD0iTTM2IDM0djItMnptMCAwdjJjMS4xMDUgMCAyLS44OTUgMi0ycy0uODk1LTItMi0yem0wIDB2LTJjLTEuMTA1IDAtMiAuODk1LTIgMnMuODk1IDIgMiAyeiIvPjwvZz48L2c+PC9zdmc+')] opacity-30"></div>
            
            <div class="relative z-10 flex flex-col justify-center items-center text-center text-white p-12 w-full">
                <div class="float-animation mb-8">
                    <div class="w-32 h-32 bg-white/20 backdrop-blur-lg rounded-3xl flex items-center justify-center shadow-2xl border border-white/30">
                        <span class="text-6xl">⚡</span>
                    </div>
                </div>
                
                <h1 class="text-5xl font-bold mb-4 drop-shadow-lg">
                    {{ config('app.name') }} Admin
                </h1>
                <p class="text-xl text-green-100 max-w-md mb-8">
                    Manage your platform with powerful tools and real-time insights
                </p>
                
                <div class="grid grid-cols-3 gap-6 mt-12 max-w-lg">
                    <div class="text-center p-4 bg-white/10 backdrop-blur-sm rounded-xl border border-white/20">
                        <div class="text-3xl font-bold mb-1">50K+</div>
                        <div class="text-sm text-green-100">Users</div>
                    </div>
                    <div class="text-center p-4 bg-white/10 backdrop-blur-sm rounded-xl border border-white/20">
                        <div class="text-3xl font-bold mb-1">₦2.5M</div>
                        <div class="text-sm text-green-100">Daily Volume</div>
                    </div>
                    <div class="text-center p-4 bg-white/10 backdrop-blur-sm rounded-xl border border-white/20">
                        <div class="text-3xl font-bold mb-1">99.9%</div>
                        <div class="text-sm text-green-100">Uptime</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="flex-1 flex items-center justify-center p-8 bg-gradient-to-br from-gray-50 to-gray-100">
            <div class="w-full max-w-md">
                <!-- Logo for mobile -->
                <div class="lg:hidden text-center mb-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg mb-4">
                        <span class="text-3xl">⚡</span>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900">Admin Portal</h2>
                </div>

                <!-- Login Card -->
                <div class="bg-white rounded-3xl shadow-2xl p-8 md:p-10 border border-gray-200">
                    <div class="text-center mb-8">
                        <div class="hidden lg:flex justify-center mb-6">
                            <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl flex items-center justify-center shadow-lg pulse-slow">
                                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                            </div>
                        </div>
                        <h2 class="text-3xl font-bold text-gray-900 mb-2">
                            Welcome Back
                        </h2>
                        <p class="text-gray-600">
                            Sign in to access your admin dashboard
                        </p>
                    </div>

                    <!-- Error Messages -->
                    @if($errors->any())
                        <div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4 animate-shake">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-semibold text-red-800 mb-1">
                                        Authentication Failed
                                    </h3>
                                    <div class="text-sm text-red-700">
                                        @foreach($errors->all() as $error)
                                            <p>{{ $error }}</p>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Login Form -->
                    <form action="{{ route('admin.login.post') }}" method="POST" class="space-y-6">
                        @csrf
                        
                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">
                                Email Address
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/>
                                    </svg>
                                </div>
                                <input
                                    id="email"
                                    name="email"
                                    type="email"
                                    autocomplete="email"
                                    required
                                    value="{{ old('email') }}"
                                    class="block w-full pl-12 pr-4 py-3.5 border border-gray-300 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                                    placeholder="admin@reup.com"
                                >
                            </div>
                        </div>

                        <!-- Password -->
                        <div>
                            <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">
                                Password
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </div>
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autocomplete="current-password"
                                    required
                                    class="block w-full pl-12 pr-4 py-3.5 border border-gray-300 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                                    placeholder="••••••••"
                                >
                            </div>
                        </div>

                        <!-- Remember & Forgot -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <input
                                    id="remember"
                                    name="remember"
                                    type="checkbox"
                                    class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded transition-all duration-200"
                                >
                                <label for="remember" class="ml-2 block text-sm text-gray-700 font-medium">
                                    Remember me
                                </label>
                            </div>

                            <div class="text-sm">
                                <a href="{{ route('password.request') }}" class="font-semibold text-green-600 hover:text-green-700 transition-colors duration-200">
                                    Forgot password?
                                </a>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button
                            type="submit"
                            class="w-full flex justify-center items-center py-4 px-4 border border-transparent rounded-xl text-sm font-bold text-white bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                            </svg>
                            Sign In to Dashboard
                        </button>
                    </form>

                    <!-- Support -->
                    <div class="mt-8 text-center">
                        <p class="text-sm text-gray-600">
                            Need help? 
                            <a href="mailto:support@reup.com" class="font-semibold text-green-600 hover:text-green-700 transition-colors duration-200">
                                Contact Support
                            </a>
                        </p>
                    </div>
                </div>

                <!-- Security Notice -->
                <div class="mt-6 text-center text-xs text-gray-500">
                    <p class="flex items-center justify-center">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                        </svg>
                        Secure admin access • All activities are logged
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>