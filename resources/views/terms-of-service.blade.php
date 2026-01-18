@extends('layouts.main')
@section('title', 'Terms of Service')
@section('main')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
        <style>
        /* Add to your main CSS file or in a <style> tag in layout */
.prose h1 {
    @apply text-2xl font-bold text-gray-900 mt-8 mb-4;
}

.prose h2 {
    @apply text-xl font-bold text-gray-900 mt-6 mb-3;
}

.prose h3 {
    @apply text-lg font-semibold text-gray-900 mt-5 mb-2;
}

.prose p {
    @apply text-gray-600 leading-relaxed mb-4;
}

.prose ul {
    @apply list-disc pl-5 text-gray-600 mb-4;
}

.prose ol {
    @apply list-decimal pl-5 text-gray-600 mb-4;
}

.prose li {
    @apply mb-2;
}

.prose strong {
    @apply font-semibold text-gray-900;
}

.prose a {
    @apply text-green-600 hover:text-green-700 underline;
}

.prose blockquote {
    @apply border-l-4 border-green-300 pl-4 italic text-gray-700 my-4;
}

.prose table {
    @apply w-full border-collapse border border-gray-300 my-4;
}

.prose th {
    @apply bg-green-50 border border-gray-300 px-4 py-2 text-left font-semibold;
}

.prose td {
    @apply border border-gray-300 px-4 py-2;
}
</style>
    <div class="py-8 md:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg mb-4">
                    <span class="text-3xl">⚖️</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Terms of Service</h1>
                
                @if($terms && $terms->version_date)
                    <p class="text-gray-600 text-sm md:text-base">Effective Date: {{ $terms->version_date->format('F d, Y') }}</p>
                @else
                    <p class="text-gray-600 text-sm md:text-base">Effective Date: {{ date('F d, Y') }}</p>
                @endif
                
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
                    @if($terms && $terms->content)
                        <!-- Dynamic content from database -->
                             <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
                              <style>
        /* Custom styles for Quill content display */
        .ql-editor {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: #374151;
            padding: 0 !important;
        }
        
        .ql-editor h1 {
            font-size: 2em !important;
            font-weight: bold !important;
            margin: 1.5em 0 0.5em 0 !important;
            color: #111827 !important;
            border-bottom: 2px solid #10b981;
            padding-bottom: 0.5em;
        }
        
        .ql-editor h2 {
            font-size: 1.5em !important;
            font-weight: bold !important;
            margin: 1.2em 0 0.5em 0 !important;
            color: #1f2937 !important;
        }
        
        .ql-editor h3 {
            font-size: 1.25em !important;
            font-weight: 600 !important;
            margin: 1em 0 0.5em 0 !important;
            color: #374151 !important;
        }
        
        .ql-editor p {
            margin: 0.8em 0 !important;
            line-height: 1.7 !important;
        }
        
        .ql-editor ul,
        .ql-editor ol {
            margin: 0.8em 0 !important;
            padding-left: 2em !important;
        }
        
        .ql-editor li {
            margin: 0.3em 0 !important;
        }
        
        .ql-editor a {
            color: #10b981 !important;
            text-decoration: underline !important;
        }
        
        .ql-editor a:hover {
            color: #059669 !important;
        }
        
        .ql-editor blockquote {
            border-left: 4px solid #10b981 !important;
            padding-left: 1em !important;
            margin: 1em 0 !important;
            font-style: italic !important;
            color: #6b7280 !important;
            background-color: #f0fdf4 !important;
            padding: 1em !important;
            border-radius: 0.375rem;
        }
        
        .ql-editor .ql-size-small {
            font-size: 0.875em !important;
        }
        
        .ql-editor .ql-size-large {
            font-size: 1.25em !important;
        }
        
        .ql-editor .ql-size-huge {
            font-size: 1.5em !important;
        }
        
        .ql-editor .ql-align-center {
            text-align: center !important;
        }
        
        .ql-editor .ql-align-right {
            text-align: right !important;
        }
        
        .ql-editor .ql-align-justify {
            text-align: justify !important;
        }
        
        .ql-editor .ql-indent-1 {
            padding-left: 3em !important;
        }
        
        .ql-editor .ql-indent-2 {
            padding-left: 6em !important;
        }
        
        .ql-editor .ql-indent-3 {
            padding-left: 9em !important;
        }
        
        .ql-editor strong {
            font-weight: 700 !important;
        }
        
        .ql-editor em {
            font-style: italic !important;
        }
        
        /* Custom classes you might use */
        .ql-editor .alert {
            padding: 1em !important;
            margin: 1em 0 !important;
            border-radius: 0.5rem !important;
            border-left: 4px solid !important;
        }
        
        .ql-editor .alert-info {
            background-color: #eff6ff !important;
            border-color: #3b82f6 !important;
            color: #1e40af !important;
        }
        
        .ql-editor .alert-warning {
            background-color: #fef3c7 !important;
            border-color: #f59e0b !important;
            color: #92400e !important;
        }
        
        .ql-editor .alert-success {
            background-color: #f0fdf4 !important;
            border-color: #10b981 !important;
            color: #065f46 !important;
        }
    </style>
                        <div class="prose prose-lg max-w-none">
                            <div class="ql-editor ql-snow">
                            {!! $terms->content !!}
                            </div>
                        </div>
                    @else
                        <!-- Static fallback content -->
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
                    @endif

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