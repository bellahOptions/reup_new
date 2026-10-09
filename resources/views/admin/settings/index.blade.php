@extends('admin.layouts.app')

@section('title', 'Site settings')
@section('page-title', 'Site settings')
@section('page-description', 'Platform configuration, fees, notifications and security.')

@section('page-actions')
    <button type="button" @click="restoreDefaults()" class="btn btn-outline btn-sm">
        <x-icon name="arrow-path" class="h-4 w-4" />
        Restore defaults
    </button>
@endsection

@section('content')
<div class="space-y-6" x-data="settingsPage">

    {{-- ============================ Tabs ============================== --}}
    <div class="border-b border-border">
        <nav class="-mb-px flex space-x-6 overflow-x-auto" id="settingsTabs" aria-label="Settings sections">
            @foreach($categories as $key => $name)
                <button type="button"
                        @click="activeTab = '{{ $key }}'"
                        data-tab="{{ $key }}"
                        class="tab-button whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition-colors"
                        :class="activeTab === '{{ $key }}' ? 'border-brand-500 text-brand-700' : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'">
                    {{ $name }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ======================== Settings form ======================== --}}
    <div class="card overflow-hidden">
        <form id="settingsForm">
            @csrf

            <div class="card-content">
                <!-- General Settings -->
                <div x-show="activeTab === 'general'" x-cloak class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="setting_site_name" class="label mb-2">Site name</label>
                            <input type="text" id="setting_site_name"
                                   data-key="site_name"
                                   data-type="text"
                                   data-category="general"
                                   class="settings-input input"
                                   placeholder="Enter site name">
                            <p class="mt-1 text-xs text-muted-foreground">The name of your platform.</p>
                        </div>
                        <div>
                            <label for="setting_site_tagline" class="label mb-2">Site tagline</label>
                            <input type="text" id="setting_site_tagline"
                                   data-key="site_tagline"
                                   data-type="text"
                                   data-category="general"
                                   class="settings-input input"
                                   placeholder="Enter site tagline">
                            <p class="mt-1 text-xs text-muted-foreground">Brief description of your platform.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="setting_site_url" class="label mb-2">Site URL</label>
                            <input type="url" id="setting_site_url"
                                   data-key="site_url"
                                   data-type="text"
                                   data-category="general"
                                   class="settings-input input"
                                   placeholder="https://example.com">
                        </div>
                        <div>
                            <label for="setting_default_currency" class="label mb-2">Default currency</label>
                            <select id="setting_default_currency"
                                    data-key="default_currency"
                                    data-type="text"
                                    data-category="general"
                                    class="settings-input select">
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
                        <label for="setting_timezone" class="label mb-2">Timezone</label>
                        <select id="setting_timezone"
                                data-key="timezone"
                                data-type="text"
                                data-category="general"
                                class="settings-input select">
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
                    <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-5">
                        <x-icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-700" />
                        <div>
                            <h3 class="text-base font-semibold text-amber-800">Maintenance mode</h3>
                            <p class="mt-1 text-sm text-amber-700">
                                When enabled, only administrators can access the site. Customers will see the maintenance page.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4">
                        <div>
                            <h4 class="text-sm font-medium">Enable maintenance mode</h4>
                            <p class="text-sm text-muted-foreground">Put the site in maintenance mode.</p>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox"
                                   data-key="maintenance_mode"
                                   data-type="boolean"
                                   data-category="maintenance"
                                   class="settings-input sr-only peer">
                            <span class="h-6 w-11 rounded-full bg-ink-300 transition-colors peer-checked:bg-brand-500"></span>
                            <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-subtle transition-transform duration-150 peer-checked:translate-x-5"></span>
                            <span class="sr-only">Enable maintenance mode</span>
                        </label>
                    </div>

                    <div>
                        <label for="setting_maintenance_message" class="label mb-2">Maintenance message</label>
                        <textarea id="setting_maintenance_message"
                                  data-key="maintenance_message"
                                  data-type="textarea"
                                  data-category="maintenance"
                                  rows="4"
                                  class="settings-input textarea"
                                  placeholder="Enter message to display during maintenance"></textarea>
                    </div>

                    <div id="maintenanceStatus" class="hidden"></div>
                </div>

                <!-- Contact Information -->
                <div x-show="activeTab === 'contact'" x-cloak class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="setting_contact_email" class="label mb-2">Contact email</label>
                            <input type="email" id="setting_contact_email"
                                   data-key="contact_email"
                                   data-type="text"
                                   data-category="contact"
                                   class="settings-input input"
                                   placeholder="contact@example.com">
                        </div>
                        <div>
                            <label for="setting_support_email" class="label mb-2">Support email</label>
                            <input type="email" id="setting_support_email"
                                   data-key="support_email"
                                   data-type="text"
                                   data-category="contact"
                                   class="settings-input input"
                                   placeholder="support@example.com">
                        </div>
                    </div>

                    <div>
                        <label for="setting_contact_phone" class="label mb-2">Contact phone</label>
                        <input type="tel" id="setting_contact_phone"
                               data-key="contact_phone"
                               data-type="text"
                               data-category="contact"
                               class="settings-input input"
                               placeholder="+234 123 456 7890">
                    </div>

                    <div>
                        <label for="setting_contact_address" class="label mb-2">Contact address</label>
                        <textarea id="setting_contact_address"
                                  data-key="contact_address"
                                  data-type="textarea"
                                  data-category="contact"
                                  rows="3"
                                  class="settings-input textarea"
                                  placeholder="Enter physical address"></textarea>
                    </div>

                    <div>
                        <label for="setting_business_hours" class="label mb-2">Business hours</label>
                        <input type="text" id="setting_business_hours"
                               data-key="business_hours"
                               data-type="text"
                               data-category="contact"
                               class="settings-input input"
                               placeholder="Mon - Fri: 9:00 AM - 6:00 PM">
                    </div>
                </div>

                <!-- SEO & Meta -->
                <div x-show="activeTab === 'seo'" x-cloak class="space-y-6">
                    <div>
                        <label for="setting_meta_title" class="label mb-2">Meta title</label>
                        <input type="text" id="setting_meta_title"
                               data-key="meta_title"
                               data-type="text"
                               data-category="seo"
                               class="settings-input input"
                               placeholder="Enter meta title">
                        <p class="mt-1 text-xs text-muted-foreground">Recommended: 50-60 characters.</p>
                    </div>

                    <div>
                        <label for="setting_meta_description" class="label mb-2">Meta description</label>
                        <textarea id="setting_meta_description"
                                  data-key="meta_description"
                                  data-type="textarea"
                                  data-category="seo"
                                  rows="3"
                                  class="settings-input textarea"
                                  placeholder="Enter meta description"></textarea>
                        <p class="mt-1 text-xs text-muted-foreground">Recommended: 150-160 characters.</p>
                    </div>

                    <div>
                        <label for="setting_meta_keywords" class="label mb-2">Meta keywords</label>
                        <input type="text" id="setting_meta_keywords"
                               data-key="meta_keywords"
                               data-type="text"
                               data-category="seo"
                               class="settings-input input"
                               placeholder="keyword1, keyword2, keyword3">
                        <p class="mt-1 text-xs text-muted-foreground">Separate keywords with commas.</p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="setting_google_analytics_id" class="label mb-2">Google Analytics ID</label>
                            <input type="text" id="setting_google_analytics_id"
                                   data-key="google_analytics_id"
                                   data-type="text"
                                   data-category="seo"
                                   class="settings-input input"
                                   placeholder="UA-XXXXXXXXX-X">
                        </div>
                        <div>
                            <label for="setting_google_site_verification" class="label mb-2">Google site verification</label>
                            <input type="text" id="setting_google_site_verification"
                                   data-key="google_site_verification"
                                   data-type="text"
                                   data-category="seo"
                                   class="settings-input input"
                                   placeholder="Enter verification code">
                        </div>
                    </div>
                </div>

                <!-- Social Media -->
                <div x-show="activeTab === 'social'" x-cloak class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="setting_social_facebook" class="label mb-2 flex items-center gap-2">
                                <x-icon name="globe-alt" class="h-4 w-4 text-ink-400" />
                                Facebook URL
                            </label>
                            <input type="url" id="setting_social_facebook"
                                   data-key="social_facebook"
                                   data-type="text"
                                   data-category="social"
                                   class="settings-input input"
                                   placeholder="https://facebook.com/yourpage">
                        </div>
                        <div>
                            <label for="setting_social_twitter" class="label mb-2 flex items-center gap-2">
                                <x-icon name="globe-alt" class="h-4 w-4 text-ink-400" />
                                Twitter URL
                            </label>
                            <input type="url" id="setting_social_twitter"
                                   data-key="social_twitter"
                                   data-type="text"
                                   data-category="social"
                                   class="settings-input input"
                                   placeholder="https://twitter.com/yourprofile">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="setting_social_instagram" class="label mb-2 flex items-center gap-2">
                                <x-icon name="globe-alt" class="h-4 w-4 text-ink-400" />
                                Instagram URL
                            </label>
                            <input type="url" id="setting_social_instagram"
                                   data-key="social_instagram"
                                   data-type="text"
                                   data-category="social"
                                   class="settings-input input"
                                   placeholder="https://instagram.com/yourprofile">
                        </div>
                        <div>
                            <label for="setting_social_linkedin" class="label mb-2 flex items-center gap-2">
                                <x-icon name="globe-alt" class="h-4 w-4 text-ink-400" />
                                LinkedIn URL
                            </label>
                            <input type="url" id="setting_social_linkedin"
                                   data-key="social_linkedin"
                                   data-type="text"
                                   data-category="social"
                                   class="settings-input input"
                                   placeholder="https://linkedin.com/company/yourcompany">
                        </div>
                    </div>

                    <div>
                        <label for="setting_social_whatsapp" class="label mb-2 flex items-center gap-2">
                            <x-icon name="chat-bubble-left-right" class="h-4 w-4 text-ink-400" />
                            WhatsApp number
                        </label>
                        <input type="tel" id="setting_social_whatsapp"
                               data-key="social_whatsapp"
                               data-type="text"
                               data-category="social"
                               class="settings-input input"
                               placeholder="+2341234567890">
                        <p class="mt-1 text-xs text-muted-foreground">Include the country code.</p>
                    </div>
                </div>

                <!-- Payment Settings -->
                <div x-show="activeTab === 'payment'" x-cloak class="space-y-6">
                    <div class="flex items-start gap-3 rounded-xl border border-border bg-surface p-5">
                        <x-icon name="wallet" class="mt-0.5 h-5 w-5 shrink-0 text-ink-500" />
                        <div>
                            <h3 class="text-base font-semibold">Payment settings</h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Configure payment-related settings including service fees and wallet limits.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="setting_payment_currency" class="label mb-2">Payment currency</label>
                            <select id="setting_payment_currency"
                                    data-key="payment_currency"
                                    data-type="text"
                                    data-category="payment"
                                    class="settings-input select">
                                <option value="NGN">NGN - Nigerian Naira</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="GBP">GBP - British Pound</option>
                            </select>
                        </div>
                        <div>
                            <label for="setting_airtime_service_fee" class="label mb-2">Airtime service fee (%)</label>
                            <input type="number" id="setting_airtime_service_fee"
                                   data-key="airtime_service_fee"
                                   data-type="number"
                                   data-category="payment"
                                   step="0.01" min="0" max="10"
                                   class="settings-input input tabular-nums"
                                   placeholder="0.00">
                            {{--
                                This field is NOT read by the purchase path, and saying
                                so here matters: it previously claimed to control the
                                airtime fee while the real charge was a hard-coded 2% in
                                `AirtimeDataController`. An operator who set it to 0 would
                                have seen no change at all.
                            --}}
                            <p class="mt-1 text-xs text-amber-700">
                                Not applied. Airtime has no customer fee — &#8358;1,000 costs
                                &#8358;1,000. To charge one, enable a customer fee on the
                                applicable rule in
                                <a href="{{ route('admin.pricing.index') }}" class="underline">Pricing</a>,
                                where it is validated and audited.
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="setting_data_service_fee" class="label mb-2">Data service fee (%)</label>
                            <input type="number" id="setting_data_service_fee"
                                   data-key="data_service_fee"
                                   data-type="number"
                                   data-category="payment"
                                   step="0.01" min="0" max="10"
                                   class="settings-input input tabular-nums"
                                   placeholder="0.00">
                            <p class="mt-1 text-xs text-amber-700">
                                Not applied. A data bundle is charged at the price the
                                pricing rule produces. Configure it in
                                <a href="{{ route('admin.pricing.index') }}" class="underline">Pricing</a>.
                            </p>
                        </div>
                        <div>
                            <label for="setting_min_wallet_funding" class="label mb-2">Minimum wallet funding (&#8358;)</label>
                            <input type="number" id="setting_min_wallet_funding"
                                   data-key="min_wallet_funding"
                                   data-type="number"
                                   data-category="payment"
                                   min="100" step="100"
                                   class="settings-input input tabular-nums"
                                   placeholder="500">
                        </div>
                    </div>

                    <div>
                        <label for="setting_max_wallet_funding" class="label mb-2">Maximum wallet funding (&#8358;)</label>
                        <input type="number" id="setting_max_wallet_funding"
                               data-key="max_wallet_funding"
                               data-type="number"
                               data-category="payment"
                               min="1000" step="1000"
                               class="settings-input input tabular-nums"
                               placeholder="100000">
                        <p class="mt-1 text-xs text-muted-foreground">Maximum amount a customer can fund their wallet with.</p>
                    </div>
                </div>

                <!-- Notifications -->
                <div x-show="activeTab === 'notifications'" x-cloak class="space-y-6">
                    <div class="flex items-start gap-3 rounded-xl border border-border bg-surface p-5">
                        <x-icon name="bell" class="mt-0.5 h-5 w-5 shrink-0 text-ink-500" />
                        <div>
                            <h3 class="text-base font-semibold">Notification settings</h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Configure how and when notifications are sent to customers and administrators.
                            </p>
                        </div>
                    </div>

                    @php
                        $notificationToggles = [
                            'email_notifications' => ['Email notifications', 'Enable email notifications for customers.'],
                            'sms_notifications' => ['SMS notifications', 'Enable SMS notifications (may incur costs).'],
                            'transaction_alerts' => ['Transaction alerts', 'Send alerts for completed transactions.'],
                            'promotional_emails' => ['Promotional emails', 'Send promotional emails to customers.'],
                            'failed_login_alert' => ['Failed login alerts', 'Send an alert on failed login attempts.'],
                            'new_user_registration_alert' => ['New registration alerts', 'Send an alert when a customer registers.'],
                            'high_value_transaction_alert' => ['High-value transaction alerts', 'Send an alert for transactions above the threshold.'],
                            'system_error_alert' => ['System error alerts', 'Send an alert when the platform records an error.'],
                        ];
                    @endphp

                    <div class="space-y-3">
                        @foreach($notificationToggles as $key => [$title, $hint])
                            <div class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4">
                                <div>
                                    <h4 class="text-sm font-medium">{{ $title }}</h4>
                                    <p class="text-sm text-muted-foreground">{{ $hint }}</p>
                                </div>
                                <label class="relative inline-flex cursor-pointer items-center">
                                    <input type="checkbox"
                                           data-key="{{ $key }}"
                                           data-type="boolean"
                                           data-category="notifications"
                                           class="settings-input sr-only peer">
                                    <span class="h-6 w-11 rounded-full bg-ink-300 transition-colors peer-checked:bg-brand-500"></span>
                                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-subtle transition-transform duration-150 peer-checked:translate-x-5"></span>
                                    <span class="sr-only">{{ $title }}</span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Security -->
                <div x-show="activeTab === 'security'" x-cloak class="space-y-6">
                    <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-5">
                        <x-icon name="shield-check" class="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                        <div>
                            <h3 class="text-base font-semibold text-red-800">Security settings</h3>
                            <p class="mt-1 text-sm text-red-700">
                                Configure security measures and publish security updates for your platform.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4">
                        <div>
                            <h4 class="text-sm font-medium">Two-factor authentication (2FA)</h4>
                            <p class="text-sm text-muted-foreground">Require 2FA for administrator accounts.</p>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox"
                                   data-key="enable_2fa"
                                   data-type="boolean"
                                   data-category="security"
                                   class="settings-input sr-only peer">
                            <span class="h-6 w-11 rounded-full bg-ink-300 transition-colors peer-checked:bg-brand-500"></span>
                            <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-subtle transition-transform duration-150 peer-checked:translate-x-5"></span>
                            <span class="sr-only">Two-factor authentication</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="setting_session_timeout" class="label mb-2">Session timeout (minutes)</label>
                            <input type="number" id="setting_session_timeout"
                                   data-key="session_timeout"
                                   data-type="number"
                                   data-category="security"
                                   min="5" max="480"
                                   class="settings-input input tabular-nums"
                                   placeholder="60">
                            <p class="mt-1 text-xs text-muted-foreground">Time before automatic logout (5-480 minutes).</p>
                        </div>
                        <div>
                            <label for="setting_max_login_attempts" class="label mb-2">Max login attempts</label>
                            <input type="number" id="setting_max_login_attempts"
                                   data-key="max_login_attempts"
                                   data-type="number"
                                   data-category="security"
                                   min="1" max="10"
                                   class="settings-input input tabular-nums"
                                   placeholder="5">
                            <p class="mt-1 text-xs text-muted-foreground">Attempts before the account is locked out.</p>
                        </div>
                    </div>

                    <div>
                        <label for="setting_password_expiry_days" class="label mb-2">Password expiry (days)</label>
                        <input type="number" id="setting_password_expiry_days"
                               data-key="password_expiry_days"
                               data-type="number"
                               data-category="security"
                               min="0" max="365"
                               class="settings-input input tabular-nums"
                               placeholder="90">
                        <p class="mt-1 text-xs text-muted-foreground">0 means passwords never expire.</p>
                    </div>

                    <div>
                        <label for="setting_password_complexity" class="label mb-2">Password complexity</label>
                        <select id="setting_password_complexity"
                                data-key="password_complexity"
                                data-type="select"
                                data-category="security"
                                class="settings-input select">
                            <option value="low">Low: at least 6 characters</option>
                            <option value="medium">Medium: at least 8 characters with letters and numbers</option>
                            <option value="high">High: at least 10 characters with letters, numbers and symbols</option>
                        </select>
                    </div>

                    <div class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4">
                        <div>
                            <h4 class="text-sm font-medium">Auto logout</h4>
                            <p class="text-sm text-muted-foreground">Sign administrators out after a period of inactivity.</p>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox"
                                   data-key="auto_logout"
                                   data-type="boolean"
                                   data-category="security"
                                   class="settings-input sr-only peer">
                            <span class="h-6 w-11 rounded-full bg-ink-300 transition-colors peer-checked:bg-brand-500"></span>
                            <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-subtle transition-transform duration-150 peer-checked:translate-x-5"></span>
                            <span class="sr-only">Auto logout</span>
                        </label>
                    </div>

                    <!-- Rich text editor for security updates -->
                    <div>
                        <span class="label mb-2">Security updates &amp; announcements</span>
                        <p class="mb-3 text-xs text-muted-foreground">Published updates are visible to customers.</p>

                        <div id="securityUpdatesEditor"
                             class="rich-text-surface mb-2 rounded-lg border border-border bg-white p-4"
                             contenteditable="true"
                             @input="syncEditor($event.target.innerHTML)"></div>

                        <textarea id="securityUpdatesInput"
                                  data-key="security_updates"
                                  data-type="textarea"
                                  data-category="security"
                                  class="settings-input hidden"></textarea>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" @click="formatText('bold')"
                                    class="btn btn-outline btn-sm font-bold" aria-label="Bold">B</button>
                            <button type="button" @click="formatText('italic')"
                                    class="btn btn-outline btn-sm italic" aria-label="Italic">I</button>
                            <button type="button" @click="formatText('underline')"
                                    class="btn btn-outline btn-sm underline" aria-label="Underline">U</button>
                            <button type="button" @click="insertBullet()"
                                    class="btn btn-outline btn-sm" aria-label="Bulleted list">&bull;</button>
                            <button type="button" @click="insertNumber()"
                                    class="btn btn-outline btn-sm tabular-nums" aria-label="Numbered list">1.</button>
                            <button type="button" @click="insertLink()"
                                    class="btn btn-outline btn-sm" aria-label="Insert link">
                                <x-icon name="link" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div>
                        <label for="setting_ip_whitelist" class="label mb-2">IP whitelist</label>
                        <textarea id="setting_ip_whitelist"
                                  data-key="ip_whitelist"
                                  data-type="textarea"
                                  data-category="security"
                                  rows="4"
                                  class="settings-input textarea font-mono text-xs"
                                  placeholder="192.168.1.1&#10;10.0.0.1&#10;::1"></textarea>
                        <p class="mt-1 text-xs text-muted-foreground">One IP address per line. Leave empty to allow all addresses.</p>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="card-footer justify-between gap-3">
                <div id="saveStatus" class="text-sm"></div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="resetChanges()" class="btn btn-outline">
                        <x-icon name="arrow-path" class="h-4 w-4" />
                        Reset changes
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="check" class="h-4 w-4" />
                        Save changes
                    </button>
                </div>
            </div>
@endsection

@push('scripts')
<script>
    // ---------------------------------------------------------------
    // Settings console
    // ---------------------------------------------------------------
    // Endpoints, payload shape (`settings: [{key, value, type, category}]`)
    // and every `data-key` / `data-type` / `data-category` / `.settings-input`
    // hook are unchanged, because SettingsManager resolves fields by those
    // attributes. What changed is presentation only:
    //   - the active tab lives in Alpine instead of a hand-registered
    //     Alpine.data callback that raced the DOMContentLoaded handler;
    //   - the rich-text toolbar buttons and the two form-action buttons lost
    //     their `onclick="..."` globals and are declared as Alpine methods
    //     here (SettingsManager itself is untouched);
    //   - the old <style> block is gone (its rules moved to app.css), and
    //     the per-tab fade animation that replayed on every switch was
    //     dropped;
    //   - the seed-defaults / check / data / update calls now use those same
    //     literal endpoints, and the console.log emoji prefixes are gone.
    document.addEventListener('alpine:init', () => {
        Alpine.data('settingsPage', () => ({
            activeTab: 'general',

            resetChanges() {
                window.settingsManager?.loadSettings();
            },

            syncEditor(html) {
                updateSecurityUpdates(html);
            },

            restoreDefaults() {
                if (!confirm('This will restore all settings to their default values. Continue?')) {
                    return;
                }

                window.settingsManager?.seedDefaults();
            },

            formatText(command) {
                document.execCommand(command, false, null);
                updateSecurityUpdates();
            },

            insertBullet() {
                // "&bull;" rather than the literal glyph: the document is
                // stored as HTML and execCommand inserts raw markup.
                document.execCommand('insertHTML', false, '&bull; ');
                updateSecurityUpdates();
            },

            insertNumber() {
                document.execCommand('insertOrderedList');
                updateSecurityUpdates();
            },

            insertLink() {
                const url = prompt('Enter the URL:', 'https://');
                if (!url) return;

                const text = prompt('Enter the link text:', url);
                if (!text) return;

                document.execCommand('createLink', false, url);
                updateSecurityUpdates();
            },
        }));
    });

    document.addEventListener('DOMContentLoaded', function () {
        // Tab clicks still update the diagnostics field the manager keeps.
        // Alpine owns the visuals; this only records the active section.
        document.querySelectorAll('.tab-button').forEach((button) => {
            button.addEventListener('click', () => {
                if (window.settingsManager) {
                    window.settingsManager.currentTab = button.dataset.tab;
                }
            });
        });

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
                    airtime_service_fee: { value: '0' },
                    data_service_fee: { value: '0' },
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

            this.initRichTextEditor();
            this.setupFormSubmission();

            await this.loadSettings();

            this.initialized = true;
        }

        initRichTextEditor() {
            const editor = document.getElementById('securityUpdatesEditor');
            const input = document.getElementById('securityUpdatesInput');

            if (!editor || !input) return;

            const sync = () => { input.value = editor.innerHTML; };

            editor.addEventListener('input', sync);
            editor.addEventListener('blur', sync);
        }

        setupFormSubmission() {
            const form = document.getElementById('settingsForm');
            if (!form) return;

            // Bound before the settings are fetched so a slow or failed load
            // can never leave the form saving twice or not at all.
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                this.saveSettings();
            });
        }

        async loadSettings() {
            try {
                showLoading('Loading settings...');

                const response = await fetch('{{ route('admin.settings.data') }}', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || 'Failed to load settings');
                }

                this.settings = data.settings || {};
                this.populateForm();
                showSuccess('Settings loaded successfully');

            } catch (error) {
                console.warn('Could not load settings from the server; using local defaults.', error);
                this.useLocalDefaults();
                showError('Failed to load settings, using defaults');
            } finally {
                hideLoading();
            }
        }

        useLocalDefaults() {
            this.settings = this.defaultSettings;
            this.hasDefaults = true;
            this.populateForm();
        }

        populateForm() {
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

                if (value === null) return;

                if (key === 'security_updates' && category === 'security') {
                    const editor = document.getElementById('securityUpdatesEditor');
                    const hiddenInput = document.getElementById('securityUpdatesInput');
                    if (editor) editor.innerHTML = value;
                    if (hiddenInput) hiddenInput.value = value;
                }
                else if (type === 'boolean') {
                    input.checked = value === '1' || value === 'true' || value === true;
                } else {
                    input.value = value;
                }
            });
        }

        collectFormData() {
            const formData = [];

            document.querySelectorAll('.settings-input').forEach(input => {
                const type = input.dataset.type;

                formData.push({
                    key: input.dataset.key,
                    value: type === 'boolean' ? (input.checked ? '1' : '0') : input.value,
                    type: type,
                    category: input.dataset.category
                });
            });

            return formData;
        }

        async saveSettings() {
            try {
                showLoading('Saving settings...');

                const response = await fetch('{{ route('admin.settings.update') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ settings: this.collectFormData() })
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Failed to save settings');
                }

                this.settings = data.settings || this.settings;
                showSuccess('Settings saved successfully');

                setTimeout(() => window.location.reload(), 1000);

            } catch (error) {
                showError(error.message || 'Failed to save settings');
            } finally {
                hideLoading();
            }
        }

        async seedDefaults() {
            try {
                showLoading('Restoring defaults...');

                const response = await fetch('{{ route('admin.settings.seed') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || 'Failed to restore defaults');
                }

                showSuccess('Defaults restored successfully');
                setTimeout(() => this.loadSettings(), 1000);

            } catch (error) {
                showError(error.message || 'Failed to restore defaults');
            }
        }
    }

    function updateSecurityUpdates(html) {
        const editor = document.getElementById('securityUpdatesEditor');
        const input = document.getElementById('securityUpdatesInput');

        if (editor && input) {
            input.value = html ?? editor.innerHTML;
        }
    }

    function showLoading(message = 'Loading...') {
        const statusEl = document.getElementById('saveStatus');
        if (!statusEl) return;

        statusEl.className = 'text-sm text-muted-foreground';
        statusEl.textContent = message;
    }

    function showSuccess(message) {
        const statusEl = document.getElementById('saveStatus');
        if (!statusEl) return;

        statusEl.className = 'text-sm text-green-700';
        statusEl.textContent = message;

        setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 3000);
    }

    function showError(message) {
        const statusEl = document.getElementById('saveStatus');
        if (!statusEl) return;

        statusEl.className = 'text-sm text-destructive';
        statusEl.textContent = message;

        setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 5000);
    }

    function hideLoading() {
        setTimeout(() => {
            const statusEl = document.getElementById('saveStatus');
            if (statusEl) statusEl.textContent = '';
        }, 500);
    }
</script>
@endpush
