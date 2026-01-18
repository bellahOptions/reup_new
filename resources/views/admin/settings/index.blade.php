@extends('admin.layouts.app')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30" x-data="{ activeTab: 'general' }">
    <div class="py-8 md:py-12">
        <!-- Rest of your content -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-8 md:mb-12">
                <div class="flex flex-col md:flex-row md:items-center justify-between">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Site Settings</h1>
                        <p class="text-gray-600">Configure platform settings and preferences</p>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <button onclick="seedDefaults()" 
                                class="px-4 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors">
                            Restore Defaults
                        </button>
                    </div>
                </div>
                <div class="w-32 h-1 bg-gradient-to-r from-green-500 to-emerald-600 mt-4 rounded-full"></div>
            </div>

            <!-- Settings Tabs -->
            <!-- Settings Tabs -->
<div class="mb-8">
    <div class="border-b border-gray-200">
        <nav class="-mb-px flex space-x-8 overflow-x-auto" id="settingsTabs">
            @foreach($categories as $key => $name)
                <button 
                    @click="activeTab = '{{ $key }}'"
                    data-tab="{{ $key }}"
                    class="tab-button whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors"
                    :class="activeTab === '{{ $key }}' ? 'border-green-500 text-green-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'">
                    {{ $name }}
                </button>
            @endforeach
        </nav>
    </div>
</div>

            <!-- Settings Form -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                <form id="settingsForm">
                    @csrf
                    
                    <!-- Tab Content -->
                    <div class="p-6 md:p-8">
                        <!-- General Settings -->
                        <div x-show="activeTab === 'general'" x-cloak class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Site Name
                                    </label>
                                    <input type="text" 
                                           data-key="site_name"
                                           data-type="text"
                                           data-category="general"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="Enter site name">
                                    <p class="text-xs text-gray-500 mt-1">The name of your platform</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Site Tagline
                                    </label>
                                    <input type="text" 
                                           data-key="site_tagline"
                                           data-type="text"
                                           data-category="general"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="Enter site tagline">
                                    <p class="text-xs text-gray-500 mt-1">Brief description of your platform</p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Site URL
                                    </label>
                                    <input type="url" 
                                           data-key="site_url"
                                           data-type="text"
                                           data-category="general"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="https://example.com">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Default Currency
                                    </label>
                                    <select data-key="default_currency"
                                            data-type="text"
                                            data-category="general"
                                            class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                                        <option value="NGN">NGN - Nigerian Naira</option>
                                        <option value="USD">USD - US Dollar</option>
                                        <option value="EUR">EUR - Euro</option>
                                        <option value="GBP">GBP - British Pound</option>
                                        <option value="GHS">GHS - Ghanaian Cedi</option>
                                        <option value="KES">KES - Kenyan Shilling</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Timezone
                                </label>
                                <select data-key="timezone"
                                        data-type="text"
                                        data-category="general"
                                        class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                                    @php
                                        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::AFRICA);
                                    @endphp
                                    @foreach($timezones as $tz)
                                        <option value="{{ $tz }}">{{ $tz }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <!-- Maintenance Mode -->
                        <div x-show="activeTab === 'maintenance'" x-cloak class="space-y-6">
                            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0">
                                        <span class="text-yellow-500 text-xl">⚠️</span>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-lg font-semibold text-yellow-800">Maintenance Mode</h3>
                                        <p class="text-yellow-700 mt-1">
                                            When enabled, only administrators can access the site. Users will see a maintenance page.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                <div>
                                    <h4 class="font-semibold text-gray-900">Enable Maintenance Mode</h4>
                                    <p class="text-sm text-gray-600">Put the site in maintenance mode</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" 
                                           data-key="maintenance_mode"
                                           data-type="boolean"
                                           data-category="maintenance"
                                           class="settings-input sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                                </label>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Maintenance Message
                                </label>
                                <textarea data-key="maintenance_message"
                                          data-type="textarea"
                                          data-category="maintenance"
                                          rows="4"
                                          class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                          placeholder="Enter message to display during maintenance"></textarea>
                            </div>
                            
                            <div id="maintenanceStatus" class="hidden">
                                <!-- Dynamic content for maintenance status -->
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div x-show="activeTab === 'contact'" x-cloak class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Contact Email
                                    </label>
                                    <input type="email" 
                                           data-key="contact_email"
                                           data-type="text"
                                           data-category="contact"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="contact@example.com">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Support Email
                                    </label>
                                    <input type="email" 
                                           data-key="support_email"
                                           data-type="text"
                                           data-category="contact"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="support@example.com">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Contact Phone
                                </label>
                                <input type="tel" 
                                       data-key="contact_phone"
                                       data-type="text"
                                       data-category="contact"
                                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                       placeholder="+234 123 456 7890">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Contact Address
                                </label>
                                <textarea data-key="contact_address"
                                          data-type="textarea"
                                          data-category="contact"
                                          rows="3"
                                          class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                          placeholder="Enter physical address"></textarea>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Business Hours
                                </label>
                                <input type="text" 
                                       data-key="business_hours"
                                       data-type="text"
                                       data-category="contact"
                                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                       placeholder="Mon - Fri: 9:00 AM - 6:00 PM">
                            </div>
                        </div>

                        <!-- SEO & Meta -->
                        <div x-show="activeTab === 'seo'" x-cloak class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Meta Title
                                </label>
                                <input type="text" 
                                       data-key="meta_title"
                                       data-type="text"
                                       data-category="seo"
                                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                       placeholder="Enter meta title">
                                <p class="text-xs text-gray-500 mt-1">Recommended: 50-60 characters</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Meta Description
                                </label>
                                <textarea data-key="meta_description"
                                          data-type="textarea"
                                          data-category="seo"
                                          rows="3"
                                          class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                          placeholder="Enter meta description"></textarea>
                                <p class="text-xs text-gray-500 mt-1">Recommended: 150-160 characters</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Meta Keywords
                                </label>
                                <input type="text" 
                                       data-key="meta_keywords"
                                       data-type="text"
                                       data-category="seo"
                                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                       placeholder="keyword1, keyword2, keyword3">
                                <p class="text-xs text-gray-500 mt-1">Separate keywords with commas</p>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Google Analytics ID
                                    </label>
                                    <input type="text" 
                                           data-key="google_analytics_id"
                                           data-type="text"
                                           data-category="seo"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="UA-XXXXXXXXX-X">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Google Site Verification
                                    </label>
                                    <input type="text" 
                                           data-key="google_site_verification"
                                           data-type="text"
                                           data-category="seo"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="Enter verification code">
                                </div>
                            </div>
                        </div>

                        <!-- Social Media -->
                        <div x-show="activeTab === 'social'" x-cloak class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                        <span class="text-blue-600 mr-2">f</span>
                                        Facebook URL
                                    </label>
                                    <input type="url" 
                                           data-key="social_facebook"
                                           data-type="text"
                                           data-category="social"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="https://facebook.com/yourpage">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                        <span class="text-blue-400 mr-2">𝕏</span>
                                        Twitter URL
                                    </label>
                                    <input type="url" 
                                           data-key="social_twitter"
                                           data-type="text"
                                           data-category="social"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="https://twitter.com/yourprofile">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                        <span class="text-pink-600 mr-2">📷</span>
                                        Instagram URL
                                    </label>
                                    <input type="url" 
                                           data-key="social_instagram"
                                           data-type="text"
                                           data-category="social"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="https://instagram.com/yourprofile">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                        <span class="text-blue-700 mr-2">in</span>
                                        LinkedIn URL
                                    </label>
                                    <input type="url" 
                                           data-key="social_linkedin"
                                           data-type="text"
                                           data-category="social"
                                           class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           placeholder="https://linkedin.com/company/yourcompany">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                    <span class="text-green-600 mr-2">💬</span>
                                    WhatsApp Number
                                </label>
                                <input type="tel" 
                                       data-key="social_whatsapp"
                                       data-type="text"
                                       data-category="social"
                                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                       placeholder="+2341234567890">
                                <p class="text-xs text-gray-500 mt-1">Include country code</p>
                            </div>
                        </div>

                        <!-- Payment Settings -->
<div x-show="activeTab === 'payment'" x-cloak class="space-y-6">
    <div class="bg-purple-50 border border-purple-200 rounded-xl p-5">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <span class="text-purple-500 text-xl">💰</span>
            </div>
            <div class="ml-3">
                <h3 class="text-lg font-semibold text-purple-800">Payment Settings</h3>
                <p class="text-purple-700 mt-1">
                    Configure payment-related settings including service fees and wallet limits.
                </p>
            </div>
        </div>
    </div>
    
    <div class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Payment Currency
                </label>
                <select data-key="payment_currency"
                        data-type="text"
                        data-category="payment"
                        class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <option value="NGN">NGN - Nigerian Naira</option>
                    <option value="USD">USD - US Dollar</option>
                    <option value="GBP">GBP - British Pound</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Airtime Service Fee (%)
                </label>
                <input type="number" 
                       data-key="airtime_service_fee"
                       data-type="number"
                       data-category="payment"
                       step="0.01" min="0" max="10"
                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                       placeholder="2.00">
                <p class="text-xs text-gray-500 mt-1">Percentage fee for airtime purchases</p>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Data Service Fee (%)
                </label>
                <input type="number" 
                       data-key="data_service_fee"
                       data-type="number"
                       data-category="payment"
                       step="0.01" min="0" max="10"
                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                       placeholder="1.50">
                <p class="text-xs text-gray-500 mt-1">Percentage fee for data purchases</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Minimum Wallet Funding (₦)
                </label>
                <input type="number" 
                       data-key="min_wallet_funding"
                       data-type="number"
                       data-category="payment"
                       min="100" step="100"
                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                       placeholder="500">
            </div>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Maximum Wallet Funding (₦)
            </label>
            <input type="number" 
                   data-key="max_wallet_funding"
                   data-type="number"
                   data-category="payment"
                   min="1000" step="1000"
                   class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                   placeholder="100000">
            <p class="text-xs text-gray-500 mt-1">Maximum amount users can fund their wallet</p>
        </div>
    </div>
</div>

<!-- Notifications -->
<div x-show="activeTab === 'notifications'" x-cloak class="space-y-6">
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-5">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <span class="text-blue-500 text-xl">🔔</span>
            </div>
            <div class="ml-3">
                <h3 class="text-lg font-semibold text-blue-800">Notification Settings</h3>
                <p class="text-blue-700 mt-1">
                    Configure how and when notifications are sent to users and administrators.
                </p>
            </div>
        </div>
    </div>
    
    <div class="space-y-4">
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">Email Notifications</h4>
                <p class="text-sm text-gray-600">Enable email notifications for users</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="email_notifications"
                       data-type="boolean"
                       data-category="notifications"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">SMS Notifications</h4>
                <p class="text-sm text-gray-600">Enable SMS notifications (may incur costs)</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="sms_notifications"
                       data-type="boolean"
                       data-category="notifications"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">Transaction Alerts</h4>
                <p class="text-sm text-gray-600">Send alerts for completed transactions</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="transaction_alerts"
                       data-type="boolean"
                       data-category="notifications"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">Promotional Emails</h4>
                <p class="text-sm text-gray-600">Send promotional emails to users</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="promotional_emails"
                       data-type="boolean"
                       data-category="notifications"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">Failed Login Alerts</h4>
                <p class="text-sm text-gray-600">Send alert on failed login attempts</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="failed_login_alert"
                       data-type="boolean"
                       data-category="notifications"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">New User Registration Alerts</h4>
                <p class="text-sm text-gray-600">Send alert when new user registers</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="new_user_registration_alert"
                       data-type="boolean"
                       data-category="notifications"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">High-Value Transaction Alerts</h4>
                <p class="text-sm text-gray-600">Send alert for transactions above threshold</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="high_value_transaction_alert"
                       data-type="boolean"
                       data-category="notifications"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">System Error Alerts</h4>
                <p class="text-sm text-gray-600">Send alert for system errors</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="system_error_alert"
                       data-type="boolean"
                       data-category="notifications"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
    </div>
</div>

<!-- Security -->
<div x-show="activeTab === 'security'" x-cloak class="space-y-6">
    <div class="bg-red-50 border border-red-200 rounded-xl p-5">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <span class="text-red-500 text-xl">🛡️</span>
            </div>
            <div class="ml-3">
                <h3 class="text-lg font-semibold text-red-800">Security Settings</h3>
                <p class="text-red-700 mt-1">
                    Configure security measures and post security updates for your platform.
                </p>
            </div>
        </div>
    </div>
    
    <div class="space-y-4">
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">Two-Factor Authentication (2FA)</h4>
                <p class="text-sm text-gray-600">Enable Two-Factor Authentication for admin accounts</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="enable_2fa"
                       data-type="boolean"
                       data-category="security"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Session Timeout (minutes)
                </label>
                <input type="number" 
                       data-key="session_timeout"
                       data-type="number"
                       data-category="security"
                       min="5" max="480"
                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                       placeholder="60">
                <p class="text-xs text-gray-500 mt-1">Time before automatic logout (5-480 minutes)</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Max Login Attempts
                </label>
                <input type="number" 
                       data-key="max_login_attempts"
                       data-type="number"
                       data-category="security"
                       min="1" max="10"
                       class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                       placeholder="5">
                <p class="text-xs text-gray-500 mt-1">Attempts before account lockout</p>
            </div>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Password Expiry (days)
            </label>
            <input type="number" 
                   data-key="password_expiry_days"
                   data-type="number"
                   data-category="security"
                   min="0" max="365"
                   class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                   placeholder="90">
            <p class="text-xs text-gray-500 mt-1">0 = Never expire</p>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Password Complexity
            </label>
            <select data-key="password_complexity"
                    data-type="select"
                    data-category="security"
                    class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                <option value="low">Low: At least 6 characters</option>
                <option value="medium">Medium: At least 8 characters with letters and numbers</option>
                <option value="high">High: At least 10 characters with letters, numbers, and symbols</option>
            </select>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
                <h4 class="font-semibold text-gray-900">Auto Logout</h4>
                <p class="text-sm text-gray-600">Enable automatic logout after inactivity</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" 
                       data-key="auto_logout"
                       data-type="boolean"
                       data-category="security"
                       class="settings-input sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
            </label>
        </div>
        
        <!-- Rich Text Editor for Security Updates -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Security Updates & Announcements
            </label>
            <p class="text-xs text-gray-500 mb-3">Post security updates that will be visible to users</p>
            
            <!-- Simple Rich Text Editor -->
            <div id="securityUpdatesEditor" class="border border-gray-300 rounded-lg min-h-48 mb-2 p-4 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500" 
                 contenteditable="true" 
                 style="min-height: 150px; max-height: 300px; overflow-y: auto;"
                 oninput="updateSecurityUpdates()">
                <!-- Content will be edited here -->
            </div>
            <textarea id="securityUpdatesInput" 
                      data-key="security_updates"
                      data-type="textarea"
                      data-category="security"
                      class="settings-input hidden"></textarea>
            
            <div class="flex space-x-2 mt-2">
                <button type="button" onclick="formatText('bold')" class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">B</button>
                <button type="button" onclick="formatText('italic')" class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">I</button>
                <button type="button" onclick="formatText('underline')" class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">U</button>
                <button type="button" onclick="insertBullet()" class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">•</button>
                <button type="button" onclick="insertNumber()" class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">1.</button>
                <button type="button" onclick="insertLink()" class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">🔗</button>
            </div>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                IP Whitelist
            </label>
            <textarea data-key="ip_whitelist"
                      data-type="textarea"
                      data-category="security"
                      rows="4"
                      class="settings-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      placeholder="192.168.1.1&#10;10.0.0.1&#10;::1"></textarea>
            <p class="text-xs text-gray-500 mt-1">One IP address per line. Leave empty to allow all IPs.</p>
        </div>
    </div>
</div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="px-6 md:px-8 py-6 bg-gray-50 border-t border-gray-200">
                        <div class="flex justify-between items-center">
                            <div id="saveStatus" class="text-sm">
                                <!-- Save status messages will appear here -->
                            </div>
                            <div class="flex space-x-4">
                                <button type="button" onclick="loadSettings()"
                                        class="px-5 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                                    Reset Changes
                                </button>
                                <button type="submit"
                                        class="px-5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors">
                                    Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('📄 Settings page loaded');
    
    // Initialize Alpine data for tabs
    if (window.Alpine) {
        window.Alpine.data('settingsTabs', () => ({
            activeTab: 'general',
            switchTab(tab) {
                this.activeTab = tab;
                console.log('🔄 Switched to tab:', tab);
            }
        }));
    }
    
    // Initialize settings manager
    window.settingsManager = new SettingsManager();
    window.settingsManager.init();
});

// Settings Management
class SettingsManager {
    constructor() {
        this.settings = {};
        this.initialized = false;
        this.hasDefaults = false;
        this.defaultSettings = this.getDefaultSettings();
        this.currentTab = 'general';
    }
    
    getDefaultSettings() {
        return {
            general: {
                site_name: { value: 'ReUp Digital Services' },
                site_tagline: { value: 'Instant Airtime & Data Services' },
                site_url: { value: window.location.origin },
                default_currency: { value: 'NGN' },
                timezone: { value: 'Africa/Lagos' }
            },
            maintenance: {
                maintenance_mode: { value: '0' },
                maintenance_message: { value: 'We are currently performing maintenance. Please check back soon.' }
            },
            contact: {
                contact_email: { value: 'reup.bellahoptions@gmail.com' },
                support_email: { value: 'support@reup.com' },
                contact_phone: { value: '+234 907 601 7916' },
                contact_address: { value: 'Lagos, Nigeria' },
                business_hours: { value: 'Mon - Fri: 9:00 AM - 6:00 PM' }
            },
            seo: {
                meta_title: { value: 'ReUp - Instant Airtime & Data Purchase Platform' },
                meta_description: { value: 'Instant airtime recharge and data bundle purchase platform for MTN, Airtel, Glo, and 9Mobile networks' },
                meta_keywords: { value: 'airtime, data, recharge, mobile, MTN, Airtel, Glo, 9Mobile, Nigeria' },
                google_analytics_id: { value: '' },
                google_site_verification: { value: '' }
            },
            social: {
                social_facebook: { value: '' },
                social_twitter: { value: '' },
                social_instagram: { value: '' },
                social_linkedin: { value: '' },
                social_whatsapp: { value: '' }
            },
            payment: {
                payment_currency: { value: 'NGN' },
                airtime_service_fee: { value: '2' },
                data_service_fee: { value: '1.5' },
                min_wallet_funding: { value: '500' },
                max_wallet_funding: { value: '100000' }
            },
            notifications: {
                email_notifications: { value: '1' },
                sms_notifications: { value: '0' },
                transaction_alerts: { value: '1' },
                promotional_emails: { value: '1' },
                failed_login_alert: { value: '1' },
                new_user_registration_alert: { value: '1' },
                high_value_transaction_alert: { value: '1' },
                system_error_alert: { value: '1' }
            },
            security: {
                enable_2fa: { value: '0' },
                session_timeout: { value: '60' },
                max_login_attempts: { value: '5' },
                password_expiry_days: { value: '90' },
                password_complexity: { value: 'medium' },
                auto_logout: { value: '1' },
                security_updates: { value: '' },
                ip_whitelist: { value: '' }
            }
        };
    }
    
    async init() {
        if (this.initialized) return;
        
        console.log('⚙️ Initializing SettingsManager...');
        
        // Initialize rich text editor
        this.initRichTextEditor();
        
        // Check if settings exist in database
        await this.checkAndSeedDefaults();
        
        // Load settings from server
        await this.loadSettings();
        
        // Setup form submission
        const form = document.getElementById('settingsForm');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveSettings();
            });
        }
        
        // Setup tab switching - DON'T interfere with Alpine
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const tab = btn.dataset.tab;
                this.currentTab = tab;
                // Let Alpine handle the visual switching
            });
        });
        
        this.initialized = true;
    }
    
    initRichTextEditor() {
        const editor = document.getElementById('securityUpdatesEditor');
        const input = document.getElementById('securityUpdatesInput');
        
        if (editor && input) {
            editor.addEventListener('input', () => {
                input.value = editor.innerHTML;
            });
            
            editor.addEventListener('blur', () => {
                input.value = editor.innerHTML;
            });
        }
    }
    
    async checkAndSeedDefaults() {
        try {
            console.log('🔍 Checking if settings exist...');
            
            const checkResponse = await fetch('/admin/settings/check', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            
            if (checkResponse.ok) {
                const data = await checkResponse.json();
                console.log('📊 Check response:', data);
                
                if (data.exists === false) {
                    console.log('📝 No settings found, seeding defaults...');
                    await this.seedDefaults();
                }
            }
        } catch (error) {
            console.warn('⚠️ Could not check/seed defaults:', error);
        }
    }
    
    async loadSettings() {
        try {
            showLoading('Loading settings...');
            console.log('🌐 Fetching settings from:', '/admin/settings/data');
            
            const response = await fetch('/admin/settings/data', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            
            console.log('📊 Response status:', response.status, response.statusText);
            
            if (!response.ok) {
                if (response.status === 404) {
                    console.error('❌ Endpoint not found (404)');
                    throw new Error('Settings endpoint not found');
                }
                throw new Error(`HTTP ${response.status}`);
            }
            
            const text = await response.text();
            console.log('📝 Raw response:', text.substring(0, 200));
            
            let data;
            try {
                data = JSON.parse(text);
                console.log('✅ Parsed JSON:', data);
            } catch (e) {
                console.error('❌ Failed to parse JSON:', e);
                throw new Error('Invalid JSON response');
            }
            
            if (data.success) {
                this.settings = data.settings || {};
                console.log('✅ Settings loaded successfully:', Object.keys(this.settings));
                
                this.populateForm();
                showSuccess('Settings loaded successfully');
            } else {
                throw new Error(data.message || 'Failed to load settings');
            }
            
        } catch (error) {
            console.error('💥 Error loading settings:', error);
            console.log('🔄 Falling back to local defaults');
            this.useLocalDefaults();
            showError('Failed to load settings, using defaults');
        } finally {
            hideLoading();
        }
    }
    
    useLocalDefaults() {
        console.log('📋 Using local default settings');
        this.settings = this.defaultSettings;
        this.hasDefaults = true;
        this.populateForm();
    }
    
    populateForm() {
        let populatedCount = 0;
        
        document.querySelectorAll('.settings-input').forEach(input => {
            const key = input.dataset.key;
            const category = input.dataset.category;
            const type = input.dataset.type;
            
            let value = null;
            
            if (this.settings[category] && this.settings[category][key]) {
                value = this.settings[category][key].value;
            }
            else if (this.defaultSettings[category] && this.defaultSettings[category][key]) {
                value = this.defaultSettings[category][key].value;
            }
            
            if (value !== null) {
                if (key === 'security_updates' && category === 'security') {
                    const editor = document.getElementById('securityUpdatesEditor');
                    const hiddenInput = document.getElementById('securityUpdatesInput');
                    if (editor) editor.innerHTML = value;
                    if (hiddenInput) hiddenInput.value = value;
                    populatedCount++;
                } 
                else if (type === 'boolean') {
                    input.checked = value === '1' || value === 'true' || value === true;
                } else {
                    input.value = value;
                }
                populatedCount++;
            }
        });
        
        console.log(`✅ Populated ${populatedCount} settings fields`);
    }
    
    collectFormData() {
        const formData = [];
        
        document.querySelectorAll('.settings-input').forEach(input => {
            const key = input.dataset.key;
            const type = input.dataset.type;
            const category = input.dataset.category;
            
            let value;
            if (type === 'boolean') {
                value = input.checked ? '1' : '0';
            } else {
                value = input.value;
            }
            
            formData.push({
                key: key,
                value: value,
                type: type,
                category: category
            });
        });
        
        return formData;
    }
    
    async saveSettings() {
        try {
            showLoading('Saving settings...');
            
            const settingsData = this.collectFormData();
            console.log('💾 Saving settings:', settingsData);
            
            const response = await fetch('/admin/settings/update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ settings: settingsData })
            });
            
            const data = await response.json();
            
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Failed to save settings');
            }
            
            this.settings = data.settings || this.settings;
            showSuccess('Settings saved successfully');
            
            // Reload page after 1 second to see changes
            setTimeout(() => window.location.reload(), 1000);
            
        } catch (error) {
            console.error('💥 Error saving settings:', error);
            showError(error.message || 'Failed to save settings');
        } finally {
            hideLoading();
        }
    }
    
    async seedDefaults() {
        try {
            console.log('🌱 Seeding default settings...');
            showLoading('Seeding defaults...');
            
            const response = await fetch('/admin/settings/seed-defaults', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                console.log('✅ Defaults seeded successfully');
                showSuccess('Defaults restored successfully');
                setTimeout(() => this.loadSettings(), 1000);
            } else {
                throw new Error(data.message);
            }
            
        } catch (error) {
            console.error('💥 Error seeding defaults:', error);
            showError(error.message || 'Failed to restore defaults');
        }
    }
}

// Global functions
window.loadSettings = function() {
    if (window.settingsManager) {
        window.settingsManager.loadSettings();
    }
};

window.seedDefaults = function() {
    if (!confirm('This will restore all settings to their default values. Continue?')) {
        return;
    }
    
    if (window.settingsManager) {
        window.settingsManager.seedDefaults();
    }
};

// Rich Text Editor Functions
function updateSecurityUpdates() {
    const editor = document.getElementById('securityUpdatesEditor');
    const input = document.getElementById('securityUpdatesInput');
    if (editor && input) {
        input.value = editor.innerHTML;
    }
}

function formatText(command) {
    document.execCommand(command, false, null);
    updateSecurityUpdates();
}

function insertBullet() {
    document.execCommand('insertHTML', false, '• ');
    updateSecurityUpdates();
}

function insertNumber() {
    document.execCommand('insertOrderedList');
    updateSecurityUpdates();
}

function insertLink() {
    const url = prompt('Enter URL:', 'https://');
    if (url) {
        const text = prompt('Enter link text:', url);
        if (text) {
            document.execCommand('createLink', false, url);
            updateSecurityUpdates();
        }
    }
}

// Utility functions
function showLoading(message = 'Loading...') {
    const statusEl = document.getElementById('saveStatus');
    if (!statusEl) return;
    
    statusEl.innerHTML = `
        <div class="flex items-center text-blue-600">
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            ${message}
        </div>
    `;
}

function showSuccess(message) {
    const statusEl = document.getElementById('saveStatus');
    if (!statusEl) return;
    
    statusEl.innerHTML = `
        <div class="flex items-center text-green-600">
            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
            </svg>
            ${message}
        </div>
    `;
    
    setTimeout(() => {
        if (statusEl) statusEl.innerHTML = '';
    }, 3000);
}

function showError(message) {
    const statusEl = document.getElementById('saveStatus');
    if (!statusEl) return;
    
    statusEl.innerHTML = `
        <div class="flex items-center text-red-600">
            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
            </svg>
            ${message}
        </div>
    `;
    
    setTimeout(() => {
        if (statusEl) statusEl.innerHTML = '';
    }, 5000);
}

function hideLoading() {
    setTimeout(() => {
        const statusEl = document.getElementById('saveStatus');
        if (statusEl) statusEl.innerHTML = '';
    }, 500);
}
</script>

<style>
[x-cloak] { display: none !important; }

.tab-button {
    transition: all 0.2s ease;
}

.tab-button:hover {
    transform: translateY(-1px);
}

.settings-input:focus {
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
}

/* Rich Text Editor Styles */
#securityUpdatesEditor {
    min-height: 150px;
    max-height: 300px;
    overflow-y: auto;
    outline: none;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    font-size: 14px;
    line-height: 1.6;
    color: #374151;
}

#securityUpdatesEditor:focus {
    outline: 2px solid #10b981;
    outline-offset: -2px;
}

#securityUpdatesEditor ul,
#securityUpdatesEditor ol {
    padding-left: 20px;
    margin: 10px 0;
}

#securityUpdatesEditor li {
    margin: 5px 0;
}

#securityUpdatesEditor p {
    margin: 10px 0;
}

#securityUpdatesEditor a {
    color: #10b981;
    text-decoration: underline;
}

#securityUpdatesEditor a:hover {
    color: #059669;
}

/* Tab content animation */
.tab-content {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
/* Settings page specific styles */
[x-cloak] {
    display: none !important;
}

.tab-button {
    transition: all 0.2s ease;
    position: relative;
}

.tab-button:hover {
    transform: translateY(-1px);
}

.tab-button.active {
    border-color: #10b981;
    color: #059669;
}

.settings-input:focus {
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
    border-color: #10b981;
}

/* Rich text editor buttons */
.rich-text-button {
    padding: 4px 8px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    background: white;
    cursor: pointer;
    font-size: 12px;
}

.rich-text-button:hover {
    background: #f3f4f6;
}

.rich-text-button.active {
    background: #10b981;
    color: white;
    border-color: #10b981;
}

/* Checkbox toggle */
.toggle-checkbox:checked {
    right: 0;
    border-color: #10b981;
}

.toggle-checkbox:checked + .toggle-label {
    background-color: #10b981;
}
</style>
@endsection