@extends('layouts.main')
@section('main')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12 text-center">
                <div class="inline-block w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center shadow-lg mb-6">
                    <span class="text-3xl">📞</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Contact Us</h1>
                <p class="text-gray-600 text-lg max-w-3xl mx-auto">Get in touch with our support team. We're here to help 24/7.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 md:gap-12">
                <!-- Contact Information -->
                <div class="space-y-8">
                    <!-- Contact Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Email Card -->
                        <div class="bg-white w-auto rounded-2xl shadow-sm border border-gray-200/60 p-6">
                            <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center mb-4">
                                <span class="text-2xl">📧</span>
                            </div>
                            <h3 class="font-bold text-gray-900 mb-2">Email Us</h3>
                            <p class="text-gray-600 text-sm mb-4">For general inquiries and support</p>
                            <a href="mailto:reup.bellahoptions@gmail.com" class="text-green-600 hover:text-green-700 font-semibold text-lg">reup@bellahoptions.com</a>
                        </div>

                        <!-- Phone Card -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                            <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center mb-4">
                                <span class="text-2xl">📱</span>
                            </div>
                            <h3 class="font-bold text-gray-900 mb-2">Call Us</h3>
                            <p class="text-gray-600 text-sm mb-4">Mon-Fri, 9am-6pm</p>
                            <a href="tel:+2349012345678" class="text-green-600 hover:text-green-700 font-semibold text-lg">+234 903 141 2354</a>
                        </div>

                        <!-- Live Chat Card -->
                        <div class="md:col-span-2 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center mb-3">
                                        <span class="text-2xl">💬</span>
                                    </div>
                                    <h3 class="font-bold text-xl mb-2">Live Chat</h3>
                                    <p class="text-green-100 text-sm mb-6">Chat instantly with our support agents</p>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="w-3 h-3 bg-green-400 rounded-full animate-pulse"></span>
                                    <span class="text-sm font-semibold">24/7 Available</span>
                                </div>
                            </div>
                            <a href="#" class="block w-full bg-white text-green-600 hover:bg-green-50 text-center py-3 rounded-xl font-bold text-sm transition-all duration-200">
                                Start Live Chat
                            </a>
                        </div>
                    </div>

                    <!-- FAQ Section -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-6 flex items-center">
                            <span class="text-xl mr-2">❓</span>
                            Frequently Asked Questions
                        </h3>
                        <div class="space-y-4">
                            <div class="border-b border-gray-100 pb-4">
                                <h4 class="font-semibold text-gray-900 mb-2">How quickly will I receive my airtime/data?</h4>
                                <p class="text-gray-600 text-sm">All recharges are delivered instantly within seconds of successful payment.</p>
                            </div>
                            <div class="border-b border-gray-100 pb-4">
                                <h4 class="font-semibold text-gray-900 mb-2">What payment methods do you accept?</h4>
                                <p class="text-gray-600 text-sm">We accept bank transfers, debit cards, and wallet funding from your account balance.</p>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-900 mb-2">Is there a service fee?</h4>
                                <p class="text-gray-600 text-sm">Yes, a 2% service fee applies to all transactions for platform maintenance and support.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Form -->
                <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6 md:p-8">
                    <h2 class="text-2xl font-bold text-gray-900 mb-2">Send us a message</h2>
                    <p class="text-gray-600 mb-6">We'll respond within 24 hours</p>

                    <form id="contactForm" action="{{ route('contact.submit') }}" method="POST" class="space-y-6">
                        @csrf
                        
                        <!-- Name -->
                        <div>
                            <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">Full Name</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <span class="text-gray-400">👤</span>
                                </div>
                                <input type="text" id="name" name="name" placeholder="Tobi Olaide" 
                                       class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                       required value="{{ auth()->user() ? auth()->user()->name : '' }}">
                            </div>
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">Email Address</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <span class="text-gray-400">📧</span>
                                </div>
                                <input type="email" id="email" name="email" placeholder="your email" 
                                       class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                       required value="{{ auth()->user() ? auth()->user()->email : '' }}">
                            </div>
                        </div>

                        <!-- Subject -->
                        <div>
                            <label for="subject" class="block text-sm font-semibold text-gray-700 mb-2">Subject</label>
                            <select id="subject" name="subject" 
                                    class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                    required>
                                <option value="">Select a topic</option>
                                <option value="General Inquiry">General Inquiry</option>
                                <option value="Technical Support">Technical Support</option>
                                <option value="Billing Issue">Billing Issue</option>
                                <option value="Feature Request">Feature Request</option>
                                <option value="Partnership">Partnership</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <!-- Message -->
                        <div>
                            <label for="message" class="block text-sm font-semibold text-gray-700 mb-2">Message</label>
                            <textarea id="message" name="message" rows="5" placeholder="How can we help you?" 
                                      class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                      required></textarea>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold py-4 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                            <span>Send Message</span>
                            <span>📤</span>
                        </button>

                        <!-- Success/Error Messages -->
                        <div id="contactMessage" class="hidden"></div>
                    </form>

                    <!-- Response Time Notice -->
                    <div class="mt-6 p-4 bg-green-50 rounded-xl border border-green-200">
                        <div class="flex items-start">
                            <span class="text-green-500 mr-3">⏰</span>
                            <div>
                                <p class="text-sm font-semibold text-green-900">Response Time</p>
                                <p class="text-xs text-green-700 mt-1">We aim to respond to all inquiries within 1-2 business hours during working days.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Company Info -->
            <div class="mt-12 pt-8 border-t border-gray-200">
                <div class="text-center">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Our Office</h3>
                    <p class="text-gray-600 max-w-2xl mx-auto">
                        <span class="inline-block mr-2">📍</span>
                        Atan Ota, Ogun State, Nigeria
                    </p>
                    <div class="flex flex-wrap justify-center gap-4 mt-6 text-sm text-gray-500">
                        <span>🕘 Mon-Fri: 9am-6pm</span>
                        <span>📧 reup.bellahoptions@gmail.com</span>
                        <span>📞 +234 903 141 2354</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded - contact page');
    
    const contactForm = document.getElementById('contactForm');
    const contactMessage = document.getElementById('contactMessage');
    
    if (!contactForm) {
        console.error('❌ Contact form not found! Check ID: #contactForm');
        return;
    }
    
    console.log('✅ Form found:', contactForm);
    console.log('✅ Form action:', contactForm.action);
    console.log('✅ CSRF Token:', document.querySelector('meta[name="csrf-token"]')?.content);

    contactForm.addEventListener('submit', async function(e) {
        console.log('🎯 Form submit event fired!');
        e.preventDefault();
        
        const submitBtn = this.querySelector('button[type="submit"]');
        if (!submitBtn) {
            console.error('❌ Submit button not found!');
            return;
        }
        
        const originalText = submitBtn.innerHTML;
        
        // Show loading
        console.log('⏳ Showing loading state...');
        submitBtn.innerHTML = '<span>Sending...</span><span class="animate-spin">⏳</span>';
        submitBtn.disabled = true;

        try {
            console.log('📦 Creating FormData...');
            const formData = new FormData(this);
            
            // Log form data for debugging
            console.log('📋 Form data entries:');
            for (let [key, value] of formData.entries()) {
                console.log(`  ${key}: ${value}`);
            }
            
            console.log('🚀 Sending fetch request to:', this.action);
            const response = await fetch(this.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content || ''
                }
            });

            console.log('📨 Response received. Status:', response.status);
            
            // Try to parse response as JSON first
            let data;
            const responseText = await response.text();
            console.log('📝 Raw response text:', responseText);
            
            try {
                data = JSON.parse(responseText);
                console.log('✅ JSON parsed successfully:', data);
            } catch (jsonError) {
                console.error('❌ Failed to parse JSON:', jsonError);
                console.log('📄 Response might be HTML. Showing in console:');
                console.log(responseText.substring(0, 500));
                throw new Error('Server returned non-JSON response. Check Laravel errors.');
            }

            if (response.ok && data.success) {
                console.log('🎉 Success!');
                contactMessage.className = 'p-4 bg-green-100 border border-green-400 text-green-700 rounded-xl';
                contactMessage.innerHTML = `
                    <div class="flex items-center">
                        <span class="text-xl mr-3">✅</span>
                        <div>
                            <p class="font-semibold">${data.message}</p>
                            <p class="text-sm mt-1">We've sent a confirmation email to <strong>${document.getElementById('email').value}</strong>.</p>
                            <p class="text-xs text-green-600 mt-2">You'll receive a response within 1-2 business hours.</p>
                        </div>
                    </div>
                `;
                this.reset();
            } else {
                console.error('❌ Server returned error:', data);
                throw new Error(data.message || 'Failed to send message');
            }
        } catch (error) {
            console.error('💥 Catch block error:', error);
            contactMessage.className = 'p-4 bg-red-100 border border-red-400 text-red-700 rounded-xl';
            contactMessage.innerHTML = `
                <div class="flex items-center">
                    <span class="text-xl mr-3">❌</span>
                    <div>
                        <p class="font-semibold">Error sending message</p>
                        <p class="text-sm mt-1">${error.message}</p>
                        <p class="text-xs text-red-600 mt-1">Please try again or contact support directly.</p>
                    </div>
                </div>
            `;
        } finally {
            console.log('🏁 Finally block - resetting UI');
            contactMessage.classList.remove('hidden');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            
            // Scroll to message
            contactMessage.scrollIntoView({ behavior: 'smooth' });
        }
    });
    
    console.log('✅ Form event listener attached successfully');
});
</script>
@endsection