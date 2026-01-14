@extends('layouts.main')
@section('main')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg mb-4">
                    <span class="text-3xl">⚖️</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Terms of Service</h1>
                <p class="text-gray-600 text-sm md:text-base">Effective Date: {{ date('F d, Y') }}</p>
                <div class="w-24 h-1 bg-gradient-to-r from-green-500 to-emerald-600 mx-auto mt-4 rounded-full"></div>
            </div>

            <!-- Acceptance Alert -->
            <div class="mb-8 bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 p-4 rounded-r-xl">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <span class="text-green-500 text-xl">📝</span>
                    </div>
                    <div class="ml-3">
                        <p class="text-green-800">
                            By accessing or using our services, you agree to be bound by these Terms of Service. Please read them carefully.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Content Card -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                <div class="p-6 md:p-8 lg:p-10">
                    <!-- 1. Agreement -->
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-white font-bold">1</span>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900">Agreement to Terms</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed">
                            These Terms of Service constitute a legally binding agreement made between you and our platform. You agree that by accessing our services, you have read, understood, and agree to be bound by all of these Terms.
                        </p>
                    </div>

                    <!-- 2. Services -->
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-white font-bold">2</span>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900">Our Services</h2>
                        </div>
                        <div class="space-y-3">
                            <p class="text-gray-600 leading-relaxed">
                                We provide airtime and data purchase services for major Nigerian telecommunications networks including MTN, Airtel, Glo, and 9Mobile.
                            </p>
                            <div class="bg-green-50/50 p-4 rounded-xl">
                                <ul class="list-disc pl-5 text-gray-600 space-y-2">
                                    <li>Instant airtime recharge services</li>
                                    <li>Data bundle purchase and activation</li>
                                    <li>Wallet funding and balance management</li>
                                    <li>Transaction history tracking</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 3. User Accounts -->
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-white font-bold">3</span>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900">User Accounts</h2>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 border border-green-200 rounded-xl">
                                <h4 class="font-semibold text-green-700 mb-2 flex items-center">
                                    <span class="text-green-500 mr-2">✅</span>
                                    Your Responsibilities
                                </h4>
                                <ul class="text-sm text-gray-600 space-y-1">
                                    <li>• Provide accurate information</li>
                                    <li>• Maintain account security</li>
                                    <li>• Notify us of unauthorized access</li>
                                    <li>• You must be at least 18 years old</li>
                                </ul>
                            </div>
                            <div class="p-4 border border-green-200 rounded-xl">
                                <h4 class="font-semibold text-green-700 mb-2 flex items-center">
                                    <span class="text-green-500 mr-2">🚫</span>
                                    Prohibited Activities
                                </h4>
                                <ul class="text-sm text-gray-600 space-y-1">
                                    <li>• Fraudulent transactions</li>
                                    <li>• Money laundering activities</li>
                                    <li>• Account sharing or selling</li>
                                    <li>• Service disruption attempts</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Payments & Fees -->
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-white font-bold">4</span>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900">Payments & Fees</h2>
                        </div>
                        <div class="space-y-4">
                            <div class="flex items-start space-x-3">
                                <div class="flex-shrink-0 w-6 h-6 bg-green-100 rounded-full flex items-center justify-center">
                                    <span class="text-green-600 text-sm">₦</span>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-1">Service Fees</h4>
                                    <p class="text-gray-600 text-sm">A service fee of 2% applies to all airtime purchases. Data purchase fees vary by plan and network.</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="flex-shrink-0 w-6 h-6 bg-green-100 rounded-full flex items-center justify-center">
                                    <span class="text-green-600 text-sm">💳</span>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-1">Payment Methods</h4>
                                    <p class="text-gray-600 text-sm">We accept various payment methods including bank transfers, debit cards, and wallet funding.</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="flex-shrink-0 w-6 h-6 bg-green-100 rounded-full flex items-center justify-center">
                                    <span class="text-green-600 text-sm">🔄</span>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-1">Refund Policy</h4>
                                    <p class="text-gray-600 text-sm">Refunds are processed within 3-5 business days for failed transactions. Service fees are non-refundable.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Limitations -->
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-white font-bold">5</span>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900">Limitations & Disclaimers</h2>
                        </div>
                        <div class="bg-yellow-50/50 border border-yellow-200 p-5 rounded-xl">
                            <div class="space-y-3">
                                <div class="flex items-start">
                                    <span class="text-yellow-500 mr-2">⚠️</span>
                                    <p class="text-gray-600 text-sm">We are not responsible for service interruptions by telecommunications providers</p>
                                </div>
                                <div class="flex items-start">
                                    <span class="text-yellow-500 mr-2">⚠️</span>
                                    <p class="text-gray-600 text-sm">Transaction completion times may vary based on network conditions</p>
                                </div>
                                <div class="flex items-start">
                                    <span class="text-yellow-500 mr-2">⚠️</span>
                                    <p class="text-gray-600 text-sm">We reserve the right to modify or discontinue services at any time</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 6. Liability -->
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-white font-bold">6</span>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900">Limitation of Liability</h2>
                        </div>
                        <div class="bg-red-50/50 border border-red-200 p-5 rounded-xl">
                            <p class="text-gray-600 leading-relaxed">
                                To the maximum extent permitted by law, we shall not be liable for any indirect, incidental, special, consequential, or punitive damages, or any loss of profits or revenues, whether incurred directly or indirectly, or any loss of data, use, goodwill, or other intangible losses.
                            </p>
                        </div>
                    </div>

                    <!-- 7. Termination -->
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-white font-bold">7</span>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900">Termination</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed">
                            We may terminate or suspend your account and bar access to our services immediately, without prior notice or liability, under our sole discretion, for any reason whatsoever, including without limitation if you breach these Terms.
                        </p>
                    </div>

                    <!-- 8. Changes -->
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-white font-bold">8</span>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900">Changes to Terms</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed">
                            We reserve the right to modify or replace these Terms at any time. If a revision is material, we will provide at least 30 days' notice prior to any new terms taking effect. Your continued use of our services after changes constitutes acceptance of those changes.
                        </p>
                    </div>

                    <!-- Contact Information -->
                    <div class="mt-10 pt-8 border-t border-gray-200">
                        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">📞</span>
                            Contact Information
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-green-50/50 p-4 rounded-xl">
                                <h4 class="font-semibold text-green-700 mb-2">Legal Inquiries</h4>
                                <p class="text-sm text-gray-600">reup.bellahoptions@gmail.com</p>
                            </div>
                            <div class="bg-green-50/50 p-4 rounded-xl">
                                <h4 class="font-semibold text-green-700 mb-2">General Support</h4>
                                <p class="text-sm text-gray-600">reup.bellahoptions@gmail.com</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
@endsection