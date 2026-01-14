@extends('layouts.main')
@section('main')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg mb-4">
                    <span class="text-3xl">🔒</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Privacy Policy</h1>
                <p class="text-gray-600 text-sm md:text-base">Last updated: {{ date('F d, Y') }}</p>
                <div class="w-24 h-1 bg-gradient-to-r from-green-500 to-emerald-600 mx-auto mt-4 rounded-full"></div>
            </div>

            <!-- Content Card -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                <div class="p-6 md:p-8 lg:p-10">
                    <!-- Introduction -->
                    <div class="mb-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">📋</span>
                            Introduction
                        </h2>
                        <p class="text-gray-600 leading-relaxed">
                            Welcome to our platform. We are committed to protecting your personal information and your right to privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our airtime and data services.
                        </p>
                    </div>

                    <!-- Information We Collect -->
                    <div class="mb-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">📊</span>
                            Information We Collect
                        </h2>
                        <div class="space-y-4">
                            <div class="bg-green-50/50 p-4 rounded-xl border border-green-100">
                                <h3 class="font-semibold text-green-700 mb-2">Personal Information</h3>
                                <ul class="list-disc pl-5 text-gray-600 space-y-1">
                                    <li>Name and contact information</li>
                                    <li>Email address and phone number</li>
                                    <li>Transaction details and purchase history</li>
                                    <li>Payment information (handled securely by our payment partners)</li>
                                </ul>
                            </div>
                            <div class="bg-green-50/50 p-4 rounded-xl border border-green-100">
                                <h3 class="font-semibold text-green-700 mb-2">Technical Information</h3>
                                <ul class="list-disc pl-5 text-gray-600 space-y-1">
                                    <li>Device information and IP address</li>
                                    <li>Browser type and operating system</li>
                                    <li>Usage data and service interaction</li>
                                    <li>Cookies and similar technologies</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- How We Use Information -->
                    <div class="mb-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">🎯</span>
                            How We Use Your Information
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg">
                                <span class="text-green-500 text-xl">✅</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Service Delivery</h4>
                                    <p class="text-sm text-gray-600">Process airtime and data purchases</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg">
                                <span class="text-green-500 text-xl">🔒</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Security</h4>
                                    <p class="text-sm text-gray-600">Protect against fraud and unauthorized access</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg">
                                <span class="text-green-500 text-xl">📈</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Improvement</h4>
                                    <p class="text-sm text-gray-600">Enhance our services and user experience</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg">
                                <span class="text-green-500 text-xl">📞</span>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Support</h4>
                                    <p class="text-sm text-gray-600">Provide customer service and support</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Data Sharing -->
                    <div class="mb-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">🤝</span>
                            Data Sharing & Disclosure
                        </h2>
                        <div class="space-y-4">
                            <div class="flex items-start">
                                <div class="flex-shrink-0 w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                                    <span class="text-green-600">1</span>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-1">Service Providers</h4>
                                    <p class="text-gray-600 text-sm">We share information with telecommunications partners to process your airtime/data purchases</p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <div class="flex-shrink-0 w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                                    <span class="text-green-600">2</span>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-1">Legal Requirements</h4>
                                    <p class="text-gray-600 text-sm">We may disclose information when required by law or to protect our rights</p>
                                </div>
                            </div>
                            <div class="flex items-start">
                                <div class="flex-shrink-0 w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                                    <span class="text-green-600">3</span>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-1">Business Transfers</h4>
                                    <p class="text-gray-600 text-sm">In case of merger, acquisition, or sale of assets</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Data Security -->
                    <div class="mb-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">🛡️</span>
                            Data Security
                        </h2>
                        <div class="bg-gradient-to-r from-green-50 to-emerald-50 p-5 rounded-xl border border-green-200">
                            <p class="text-gray-600 leading-relaxed">
                                We implement appropriate technical and organizational security measures to protect your personal information against unauthorized access, alteration, disclosure, or destruction. However, no method of transmission over the Internet or electronic storage is 100% secure.
                            </p>
                        </div>
                    </div>

                    <!-- Your Rights -->
                    <div class="mb-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">👤</span>
                            Your Privacy Rights
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="text-center p-4 bg-white border border-green-200 rounded-xl">
                                <div class="text-green-500 text-2xl mb-2">👁️</div>
                                <h4 class="font-semibold text-gray-900 mb-1">Access</h4>
                                <p class="text-sm text-gray-600">Request access to your data</p>
                            </div>
                            <div class="text-center p-4 bg-white border border-green-200 rounded-xl">
                                <div class="text-green-500 text-2xl mb-2">✏️</div>
                                <h4 class="font-semibold text-gray-900 mb-1">Correction</h4>
                                <p class="text-sm text-gray-600">Request correction of your data</p>
                            </div>
                            <div class="text-center p-4 bg-white border border-green-200 rounded-xl">
                                <div class="text-green-500 text-2xl mb-2">🗑️</div>
                                <h4 class="font-semibold text-gray-900 mb-1">Deletion</h4>
                                <p class="text-sm text-gray-600">Request deletion of your data</p>
                            </div>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="bg-green-50/50 p-6 rounded-xl border border-green-100">
                        <h3 class="text-lg font-bold text-gray-900 mb-3 flex items-center">
                            <span class="text-green-500 mr-2">📞</span>
                            Contact Us
                        </h3>
                        <p class="text-gray-600 mb-4">
                            If you have questions about this Privacy Policy or our privacy practices, please contact us:
                        </p>
                        <div class="space-y-2">
                            <div class="flex items-center text-gray-700">
                                <span class="text-green-500 mr-2">📧</span>
                                <span>Email: reup.bellahoptions@gmail.com</span>
                            </div>
                            <div class="flex items-center text-gray-700">
                                <span class="text-green-500 mr-2">📱</span>
                                <span>Phone: +234 907 601 7916</span>
                            </div>
                        </div>
                    </div>

                    <!-- Update Notice -->
                    <div class="mt-8 pt-6 border-t border-gray-200">
                        <div class="flex items-start">
                            <span class="text-yellow-500 mr-2">⚠️</span>
                            <p class="text-sm text-gray-600">
                                We may update this Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last Updated" date.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
@endsection