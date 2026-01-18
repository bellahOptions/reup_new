@extends('layouts.main')
@section('title', 'Frequently Asked Questions')
@section('main')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg mb-4">
                    <span class="text-3xl">❓</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Frequently Asked Questions</h1>
                <p class="text-gray-600 text-sm md:text-base">Find quick answers to common questions</p>
                <div class="w-24 h-1 bg-gradient-to-r from-green-500 to-emerald-600 mx-auto mt-4 rounded-full"></div>
            </div>

            <!-- Search Bar -->
            <div class="mb-8">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-gray-400">🔍</span>
                    </div>
                    <input type="text" id="faqSearch" placeholder="Search questions..." 
                           class="block w-full pl-12 pr-4 py-4 border border-gray-300 rounded-2xl focus:ring-2 focus:ring-green-500 focus:border-transparent shadow-sm transition-all duration-200">
                </div>
            </div>

            <!-- Content Card -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                <div class="p-6 md:p-8 lg:p-10">
                    <!-- Category: Getting Started -->
                    <div class="mb-10">
                        <div class="flex items-center mb-6">
                            <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center mr-3">
                                <span class="text-white">🚀</span>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-900">Getting Started</h2>
                        </div>
                        
                        <div class="space-y-4">
                            <!-- FAQ Item 1 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">How do I create an account?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        Creating an account is simple! Click on the "Sign Up" button, provide your email address, phone number, and create a secure password. Verify your email, and you're ready to start purchasing airtime and data.
                                    </p>
                                    <div class="mt-3 p-3 bg-green-50 rounded-lg">
                                        <p class="text-sm text-green-700 flex items-center">
                                            <span class="mr-2">💡</span>
                                            Pro tip: Complete your profile to enjoy faster transactions!
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 2 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">Is there a minimum deposit amount?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        Yes, the minimum deposit amount is ₦100. This ensures you can make meaningful purchases while keeping transaction costs reasonable.
                                    </p>
                                    <div class="mt-3 flex items-center text-sm text-gray-500">
                                        <span class="mr-2">💰</span>
                                        <span>Maximum deposit: ₦100,000 per transaction</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Category: Airtime & Data -->
                    <div class="mb-10">
                        <div class="flex items-center mb-6">
                            <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center mr-3">
                                <span class="text-white">📱</span>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-900">Airtime & Data</h2>
                        </div>
                        
                        <div class="space-y-4">
                            <!-- FAQ Item 3 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">How long does it take for airtime to be delivered?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        Airtime is delivered instantly! In most cases, you'll receive the airtime within 10-30 seconds of completing your purchase. If there's any delay, it's usually due to network congestion.
                                    </p>
                                    <div class="mt-3 grid grid-cols-2 gap-2">
                                        <div class="text-center p-2 bg-green-50 rounded-lg">
                                            <span class="text-green-600 font-bold">Normal</span>
                                            <p class="text-xs text-gray-600">10-30 seconds</p>
                                        </div>
                                        <div class="text-center p-2 bg-yellow-50 rounded-lg">
                                            <span class="text-yellow-600 font-bold">Peak Hours</span>
                                            <p class="text-xs text-gray-600">1-2 minutes</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 4 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">What networks do you support?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        We support all major Nigerian telecommunications networks:
                                    </p>
                                    <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3">
                                        <div class="text-center p-3 bg-yellow-50 rounded-lg">
                                            <span class="font-semibold text-yellow-700">MTN</span>
                                        </div>
                                        <div class="text-center p-3 bg-red-50 rounded-lg">
                                            <span class="font-semibold text-red-700">Airtel</span>
                                        </div>
                                        <div class="text-center p-3 bg-green-50 rounded-lg">
                                            <span class="font-semibold text-green-700">Glo</span>
                                        </div>
                                        <div class="text-center p-3 bg-blue-50 rounded-lg">
                                            <span class="font-semibold text-blue-700">9Mobile</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 5 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">Can I buy data for someone else?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        Absolutely! You can purchase data for any Nigerian phone number. Just enter the recipient's phone number when making the purchase. They'll receive the data instantly.
                                    </p>
                                    <div class="mt-3 p-3 bg-blue-50 rounded-lg">
                                        <p class="text-sm text-blue-700 flex items-center">
                                            <span class="mr-2">🎁</span>
                                            Perfect for gifting data to friends and family!
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Category: Payments & Wallet -->
                    <div class="mb-10">
                        <div class="flex items-center mb-6">
                            <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center mr-3">
                                <span class="text-white">💳</span>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-900">Payments & Wallet</h2>
                        </div>
                        
                        <div class="space-y-4">
                            <!-- FAQ Item 6 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">What payment methods do you accept?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        We accept various payment methods for your convenience:
                                    </p>
                                    <div class="mt-3 space-y-2">
                                        <div class="flex items-center">
                                            <span class="text-green-500 mr-2">✅</span>
                                            <span class="text-sm">Bank Transfer</span>
                                        </div>
                                        <div class="flex items-center">
                                            <span class="text-green-500 mr-2">✅</span>
                                            <span class="text-sm">Debit/Credit Cards</span>
                                        </div>
                                        <div class="flex items-center">
                                            <span class="text-green-500 mr-2">✅</span>
                                            <span class="text-sm">USSD Payments</span>
                                        </div>
                                        <div class="flex items-center">
                                            <span class="text-green-500 mr-2">✅</span>
                                            <span class="text-sm">Wallet Funding</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 7 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">How do I withdraw from my wallet?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        To withdraw funds from your wallet:
                                    </p>
                                    <ol class="mt-2 list-decimal pl-5 text-gray-600 space-y-2">
                                        <li>Go to your Wallet section</li>
                                        <li>Click "Withdraw Funds"</li>
                                        <li>Enter the amount (minimum ₦500)</li>
                                        <li>Provide your bank details</li>
                                        <li>Confirm withdrawal</li>
                                    </ol>
                                    <div class="mt-3 p-3 bg-green-50 rounded-lg">
                                        <p class="text-sm text-green-700">
                                            💡 Withdrawals are processed within 24 hours on business days.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 8 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">Are there any transaction fees?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        Yes, we charge minimal fees to maintain our service:
                                    </p>
                                    <div class="mt-3 space-y-2">
                                        <div class="flex justify-between items-center p-2 bg-gray-50 rounded">
                                            <span>Airtime Purchase</span>
                                            <span class="font-semibold text-green-600">2% fee</span>
                                        </div>
                                        <div class="flex justify-between items-center p-2 bg-gray-50 rounded">
                                            <span>Data Purchase</span>
                                            <span class="font-semibold text-green-600">₦50 fixed</span>
                                        </div>
                                        <div class="flex justify-between items-center p-2 bg-gray-50 rounded">
                                            <span>Withdrawal</span>
                                            <span class="font-semibold text-green-600">₦100 fixed</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Category: Troubleshooting -->
                    <div class="mb-10">
                        <div class="flex items-center mb-6">
                            <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center mr-3">
                                <span class="text-white">🔧</span>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-900">Troubleshooting</h2>
                        </div>
                        
                        <div class="space-y-4">
                            <!-- FAQ Item 9 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">What if my transaction fails?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        If your transaction fails:
                                    </p>
                                    <ul class="mt-2 list-disc pl-5 text-gray-600 space-y-2">
                                        <li>Check your internet connection</li>
                                        <li>Verify your wallet balance</li>
                                        <li>Ensure the phone number is correct</li>
                                        <li>Wait 5 minutes and try again</li>
                                    </ul>
                                    <div class="mt-3 p-3 bg-yellow-50 rounded-lg">
                                        <p class="text-sm text-yellow-700 flex items-center">
                                            <span class="mr-2">⏳</span>
                                            If deducted but not delivered, funds auto-refund in 10 minutes
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- FAQ Item 10 -->
                            <div class="faq-item bg-gray-50/50 rounded-xl border border-gray-200 overflow-hidden">
                                <button class="faq-question w-full text-left p-5 flex items-center justify-between hover:bg-gray-50 transition-all duration-200">
                                    <span class="font-semibold text-gray-900 text-base">How do I contact customer support?</span>
                                    <span class="text-green-500 text-xl transform transition-transform duration-200">➕</span>
                                </button>
                                <div class="faq-answer hidden px-5 pb-5">
                                    <p class="text-gray-600 leading-relaxed">
                                        We offer multiple support channels:
                                    </p>
                                    <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div class="text-center p-3 bg-green-50 rounded-lg">
                                            <span class="text-2xl">📧</span>
                                            <p class="font-semibold text-green-700">Email</p>
                                            <p class="text-xs text-gray-600">reup.bellahoptions@gmail.com</p>
                                        </div>
                                        <div class="text-center p-3 bg-blue-50 rounded-lg">
                                            <span class="text-2xl">💬</span>
                                            <p class="font-semibold text-blue-700">Live Chat</p>
                                            <p class="text-xs text-gray-600">24/7 on website</p>
                                        </div>
                                        <div class="text-center p-3 bg-purple-50 rounded-lg">
                                            <span class="text-2xl">📞</span>
                                            <p class="font-semibold text-purple-700">Phone</p>
                                            <p class="text-xs text-gray-600">+234 907 601 7916</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Still Need Help -->
                    <div class="mt-12 pt-8 border-t border-gray-200">
                        <div class="text-center">
                            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg mb-4">
                                <span class="text-3xl text-white">💬</span>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900 mb-3">Still Need Help?</h3>
                            <p class="text-gray-600 mb-6 max-w-md mx-auto">
                                Can't find the answer you're looking for? Our support team is here to help!
                            </p>
                            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                                <a href="mailto:reup.bellahoptions@gmail.com" class="inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white font-semibold rounded-xl hover:from-green-600 hover:to-emerald-700 shadow-lg hover:shadow-xl transition-all duration-200">
                                    <span class="mr-2">📧</span>
                                    Email Support
                                </a>
                                <a href="{{ route('contact') }}" class="inline-flex items-center justify-center px-6 py-3 border-2 border-green-500 text-green-600 font-semibold rounded-xl hover:bg-green-50 transition-all duration-200">
                                    <span class="mr-2">📞</span>
                                    Contact Us
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // FAQ Accordion Functionality
    const faqQuestions = document.querySelectorAll('.faq-question');
    
    faqQuestions.forEach(question => {
        question.addEventListener('click', function() {
            const answer = this.nextElementSibling;
            const icon = this.querySelector('span:last-child');
            
            // Toggle current FAQ
            answer.classList.toggle('hidden');
            icon.textContent = answer.classList.contains('hidden') ? '➕' : '➖';
            
            // Close other FAQs
            faqQuestions.forEach(otherQuestion => {
                if (otherQuestion !== this) {
                    const otherAnswer = otherQuestion.nextElementSibling;
                    const otherIcon = otherQuestion.querySelector('span:last-child');
                    otherAnswer.classList.add('hidden');
                    otherIcon.textContent = '➕';
                }
            });
        });
    });
    
    // FAQ Search Functionality
    const faqSearch = document.getElementById('faqSearch');
    const faqItems = document.querySelectorAll('.faq-item');
    
    faqSearch.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        
        faqItems.forEach(item => {
            const question = item.querySelector('.faq-question').textContent.toLowerCase();
            const answer = item.querySelector('.faq-answer').textContent.toLowerCase();
            
            if (question.includes(searchTerm) || answer.includes(searchTerm)) {
                item.style.display = 'block';
                // Open matching FAQs
                if (searchTerm.length > 0) {
                    item.querySelector('.faq-answer').classList.remove('hidden');
                    item.querySelector('.faq-question span:last-child').textContent = '➖';
                }
            } else {
                item.style.display = 'none';
            }
        });
    });
});
</script>

<style>
.faq-question:hover {
    background-color: rgba(16, 185, 129, 0.05);
}

.faq-answer {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
@endsection