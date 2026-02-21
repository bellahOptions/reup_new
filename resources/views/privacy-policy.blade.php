@extends('layouts.main')
@section('title', 'Privacy Policy')
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
                    <span class="text-3xl">🔒</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Privacy Policy</h1>
                
                @if($privacy && $privacy->version_date)
                    <p class="text-gray-600 text-sm md:text-base">Last updated: {{ $privacy->version_date->format('F d, Y') }}</p>
                @else
                    <p class="text-gray-600 text-sm md:text-base">Last updated: {{ date('F d, Y') }}</p>
                @endif
                
                <div class="w-24 h-1 bg-gradient-to-r from-green-500 to-emerald-600 mx-auto mt-4 rounded-full"></div>
            </div>

            <!-- Content Card -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                <div class="p-6 md:p-8 lg:p-10">
                    @if($privacy && $privacy->content)
                        <!-- Dynamic content from database -->
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
                            {!! $privacy->content !!}
                            </div>
                        </div>
                    @else
                        <!-- Static fallback content -->
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
                    @endif

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
                                <span>Email:{{ $siteSettings['support_email'] ?? '' }}</span>
                            </div>
                            <div class="flex items-center text-gray-700">
                                <span class="text-green-500 mr-2">📱</span>
                                <span>Phone: {{ $siteSettings['contact_phone'] ?? '' }}</span>
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