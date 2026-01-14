<footer id="footer" class="relative bg-gradient-to-br from-green-600 to-green-800 text-white overflow-hidden">
    <!-- Decorative Background Elements -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 right-0 w-96 h-96 bg-white/5 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 left-0 w-80 h-80 bg-white/5 rounded-full blur-3xl"></div>
    </div>

    <!-- Main Footer Content -->
    <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-16">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 md:gap-12 mb-12">
            <!-- About Section -->
            <div class="lg:col-span-2">
                <img src="{{ asset('images/reup-04.svg')}}" alt="Reup Logo" class="h-8 md:h-10 mb-6 brightness-0 invert">
                <h3 class="text-xl md:text-2xl font-bold mb-4">About ReUp</h3>
                <p class="text-sm md:text-base text-green-100 leading-relaxed max-w-md">
                    ReUp is your go-to platform for buying airtime, data, and paying bills instantly. We prioritize speed, security, and convenience to make your transactions hassle-free. 🚀
                </p>
                
                <div class="flex items-center space-x-4 mt-6">
                    <a href="https://web.facebook.com/reupByBellah/" class="w-10 h-10 bg-white/10 hover:bg-white/20 rounded-full flex items-center justify-center transition-all duration-300 hover:scale-110">
                        <span class="text-xl"><i class="fa-brands fa-facebook-f"></i></span>
                    </a>
                    <a href="https://x.com/ReupNG" class="w-10 h-10 bg-white/10 hover:bg-white/20 rounded-full flex items-center justify-center transition-all duration-300 hover:scale-110">
                        <span class="text-xl"><i class="fa-brands fa-x-twitter"></i></span>
                    </a>
                    <a href="https://www.instagram.com/reup.ng/" class="w-10 h-10 bg-white/10 hover:bg-white/20 rounded-full flex items-center justify-center transition-all duration-300 hover:scale-110">
                        <span class="text-xl"><i class="fa-brands fa-instagram"></i></span>
                    </a>
                </div>
            </div>

            <!-- Quick Links -->
            <div>
                <h3 class="text-lg md:text-xl font-bold mb-6">Quick Links</h3>
                <ul class="space-y-3">
                    <li>
                        <a href="#" class="text-sm md:text-base text-green-100 hover:text-white hover:translate-x-1 inline-block transition-all duration-300">
                            → Home
                        </a>
                    </li>
                    <li>
                        <a href="#" class="text-sm md:text-base text-green-100 hover:text-white hover:translate-x-1 inline-block transition-all duration-300">
                            → Buy Airtime
                        </a>
                    </li>
                    <li>
                        <a href="#" class="text-sm md:text-base text-green-100 hover:text-white hover:translate-x-1 inline-block transition-all duration-300">
                            → Buy Data
                        </a>
                    </li>
                    <li>
                        <a href="#" class="text-sm md:text-base text-green-100 hover:text-white hover:translate-x-1 inline-block transition-all duration-300">
                            → Pay Bills
                        </a>
                    </li>
                    <li>
                        <a href="#" class="text-sm md:text-base text-green-100 hover:text-white hover:translate-x-1 inline-block transition-all duration-300">
                            → Contact
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Contact Section -->
            <div>
                <h3 class="text-lg md:text-xl font-bold mb-6">Contact Us</h3>
                <p class="text-sm md:text-base text-green-100 leading-relaxed mb-4">
                    Have questions or need assistance? Reach out to our support team:
                </p>
                
                <div class="space-y-3">
                    <div class="flex items-start space-x-3">
                        <span class="text-lg mt-0.5">📧</span>
                        <div>
                            <p class="text-xs text-green-200 mb-1">Email</p>
                            <a href="mailto:reup.bellahoptions@gmail.com" class="text-sm md:text-base text-white hover:text-green-200 transition-colors duration-300 font-medium">
                                reup.bellahoptions@gmail.com
                            </a>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-3">
                        <span class="text-lg mt-0.5">📱</span>
                        <div>
                            <p class="text-xs text-green-200 mb-1">Phone</p>
                            <a href="tel:+2341234567890" class="text-sm md:text-base text-white hover:text-green-200 transition-colors duration-300 font-medium">
                                +234 907 601 7916
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-white/20 pt-8">
            <div class="flex flex-col md:flex-row items-center justify-between space-y-4 md:space-y-0">
                <div class="text-center md:text-left">
                    <p class="text-sm md:text-base text-green-100">
                        &copy; {{ date('Y') }} ReUp. All rights reserved.
                    </p>
                </div>
                
                <div class="text-center md:text-right">
                    <p class="text-sm text-green-100">
                        Created, Maintained and managed by 
                        <a href="https://www.bellahoptions.com" target="_blank" rel="noopener noreferrer" class="text-white hover:text-green-200 transition-colors duration-300 font-semibold inline-flex items-center">
                            Bellah Options
                            <svg class="w-3 h-3 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                        </a>
                    </p>
                </div>
            </div>

            <!-- Additional Links (Optional) -->
            <div class="flex flex-wrap items-center justify-center gap-4 md:gap-6 mt-6 text-xs md:text-sm text-green-200">
                <a href="{{ route('privacy-policy') }}" class="hover:text-white transition-colors duration-300">Privacy Policy</a>
                <span class="text-green-300">•</span>
                <a href="{{ route('terms-of-service') }}" class="hover:text-white transition-colors duration-300">Terms of Service</a>
                <span class="text-green-300">•</span>
                <a href="{{ route('faq') }}" class="hover:text-white transition-colors duration-300">FAQ</a>
            </div>
        </div>
    </div>
</footer>