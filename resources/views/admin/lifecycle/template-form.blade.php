@extends('admin.layouts.app')

@section('title', $template->exists ? 'Edit template' : 'New template')
@section('page-title', $template->exists ? $template->name : 'New template')
@section('page-description', 'Subject, body and call-to-action buttons.')

@section('content')
<div class="space-y-6"
     x-data="lifecycleTemplate({
        previewUrl: @js($template->exists ? route('admin.lifecycle.templates.preview', $template, false) : null),
        testUrl: @js($template->exists ? route('admin.lifecycle.templates.test', $template, false) : null),
     })">

    @if($errors->any())
        <div class="alert alert-destructive">
            <p class="text-sm font-medium">That could not be saved</p>
            <ul class="mt-1 list-inside list-disc text-sm">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $template->exists ? route('admin.lifecycle.templates.update', $template, false) : route('admin.lifecycle.templates.store', [], false) }}"
          class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        @csrf
        @if($template->exists)
            @method('PUT')
        @endif

        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Content</h2>
                </div>

                <div class="card-content space-y-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="key" class="label">Key</label>
                            <input id="key" name="key" type="text" required
                                   value="{{ old('key', $template->key) }}"
                                   placeholder="welcome-email"
                                   pattern="[a-z0-9._-]+"
                                   class="input mt-1.5 @error('key') input-error @enderror">
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Lowercase letters, numbers, dots, dashes. Used by automations and never shown to customers.
                            </p>
                        </div>

                        <div>
                            <label for="name" class="label">Name</label>
                            <input id="name" name="name" type="text" required
                                   value="{{ old('name', $template->name) }}"
                                   class="input mt-1.5 @error('name') input-error @enderror">
                        </div>
                    </div>

                    <div>
                        <label for="subject" class="label">Subject</label>
                        <input id="subject" name="subject" type="text" required maxlength="255"
                               value="{{ old('subject', $template->subject) }}"
                               class="input mt-1.5 @error('subject') input-error @enderror">
                    </div>

                    <div>
                        <label for="preview_text" class="label">Preview text</label>
                        <input id="preview_text" name="preview_text" type="text" maxlength="255"
                               value="{{ old('preview_text', $template->preview_text) }}"
                               class="input mt-1.5">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            The line an inbox shows next to the subject.
                        </p>
                    </div>

                    <div>
                        <label for="html_body" class="label">HTML body</label>
                        <textarea id="html_body" name="html_body" rows="14" required
                                  class="textarea mt-1.5 font-mono text-xs @error('html_body') input-error @enderror">{{ old('html_body', $template->html_body) }}</textarea>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Sanitised on save: only email-safe tags survive, scripts and event handlers are removed.
                        </p>
                    </div>

                    <div>
                        <label for="text_body" class="label">Plain-text fallback</label>
                        <textarea id="text_body" name="text_body" rows="5"
                                  class="textarea mt-1.5 font-mono text-xs">{{ old('text_body', $template->text_body) }}</textarea>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Left blank, a readable version is generated from the HTML.
                        </p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Call-to-action buttons</h2>
                    <p class="card-description">The first is styled as the primary button.</p>
                </div>

                <div class="card-content space-y-3">
                    @php
                        $buttons = old('cta_label') ? array_map(null, (array) old('cta_label'), (array) old('cta_url', [])) : ($template->cta_buttons ?? []);
                    @endphp

                    {{--
                        The URL placeholder is plain text rather than an example variable.
                        Writing `{{ wallet_url }}` inside a `{{ }}` expression does not
                        work — Blade closes the echo at the first `}}` it meets, which is
                        the one inside the string, and the view fails to compile with an
                        error that names a line rather than the nesting. The hint below
                        the fields says which variables are usable instead.
                    --}}
                    @for($i = 0; $i < 3; $i++)
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <input name="cta_label[]" type="text" maxlength="60"
                                   value="{{ $buttons[$i]['label'] ?? '' }}"
                                   placeholder="{{ $i === 0 ? 'Fund My Wallet' : 'Button label' }}"
                                   class="input">
                            <input name="cta_url[]" type="text" maxlength="500"
                                   value="{{ $buttons[$i]['url'] ?? '' }}"
                                   placeholder="{{ $i === 0 ? 'wallet_url, or a full https:// address' : 'A variable name, or a full https:// address' }}"
                                   class="input">
                        </div>
                    @endfor

                    <p class="text-xs text-muted-foreground">
                        Only <code>http</code>, <code>https</code>, <code>mailto</code> and <code>tel</code> URLs are
                        accepted. A button with a URL that fails validation is dropped rather than rendered.
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Settings</h2>
                </div>

                <div class="card-content space-y-5">
                    <div>
                        <label for="category" class="label">Category</label>
                        <select id="category" name="category" class="select mt-1.5">
                            <option value="marketing" @selected(old('category', $template->category) === 'marketing')>Marketing</option>
                            <option value="transactional" @selected(old('category', $template->category) === 'transactional')>Transactional</option>
                        </select>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Marketing can be paused, unsubscribed from and suppressed.
                            <strong>Transactional is never blocked by any of those</strong> — use it for receipts,
                            funding confirmations and security notices.
                        </p>
                    </div>

                    <div>
                        <label for="from_name" class="label">Sender display name</label>
                        <input id="from_name" name="from_name" type="text" maxlength="120"
                               value="{{ old('from_name', $template->from_name) }}"
                               placeholder="{{ config('app.name') }}"
                               class="input mt-1.5">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Blank uses the platform default. A named human sender, such as
                            &ldquo;Olumuyiwa from {{ config('app.name') }}&rdquo;, works well on engagement email and
                            less well on a receipt.
                        </p>
                    </div>

                    <div>
                        <label for="from_address" class="label">Sender address</label>
                        <input id="from_address" name="from_address" type="email" maxlength="190"
                               value="{{ old('from_address', $template->from_address) }}"
                               placeholder="{{ config('mail.from.address') }}"
                               class="input mt-1.5">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Blank uses <code>MAIL_FROM_ADDRESS</code>, which is the address verified with the mail
                            provider. An unverified address here is rejected by most providers and damages the
                            sending domain for every other email.
                        </p>
                    </div>
                </div>

                <div class="card-footer justify-between">
                    <a href="{{ route('admin.lifecycle.templates') }}" class="btn btn-ghost btn-sm">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-sm">Save template</button>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Approved variables</h2>
                    <p class="card-description">These, and only these, are substituted.</p>
                </div>
                <div class="card-content">
                    {{--
                        The braces are HTML entities rather than literals.
                        `{{ '{{ ' . $name . ' }}' }}` reads as a nested echo and Blade
                        closes it at the first `}}` — which is the one inside the string —
                        leaving an unclosed `(` that fails at compile time with a line
                        number and no explanation. `&#123;` is a brace that the compiler
                        never sees.
                    --}}
                    <dl class="space-y-2 text-xs">
                        @foreach($variables as $name => $description)
                            <div>
                                <dt class="font-mono font-medium">&#123;&#123; {{ $name }} &#125;&#125;</dt>
                                <dd class="text-muted-foreground">{{ $description }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-xs text-muted-foreground">
                        An unknown variable is left visible rather than blanked, so a typo shows up in the preview
                        instead of shipping a hole.
                    </p>
                </div>
            </div>

            @if($template->exists && isset($versions) && $versions->isNotEmpty())
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Version history</h2>
                    </div>
                    <div class="card-content space-y-2">
                        @foreach($versions as $version)
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <span>
                                    <span class="font-medium">v{{ $version->version }}</span>
                                    <span class="text-muted-foreground">
                                        {{ $version->created_at?->format('d M Y H:i') }}
                                        @if($version->note) &middot; {{ $version->note }} @endif
                                    </span>
                                </span>
                                @if(auth()->user()->hasPermission('manage_lifecycle') && $version->version !== $template->current_version)
                                    <button type="button"
                                            class="link"
                                            @click="$refs.rollback{{ $version->version }}.submit()">Restore</button>
                                @endif
                            </div>
                        @endforeach

                        @foreach($versions as $version)
                            @if(auth()->user()->hasPermission('manage_lifecycle') && $version->version !== $template->current_version)
                                <form x-ref="rollback{{ $version->version }}" method="POST" class="hidden"
                                      action="{{ route('admin.lifecycle.templates.rollback', [$template, $version->version], false) }}">
                                    @csrf
                                </form>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </form>

    @if($template->exists)
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Preview and test</h2>
                <p class="card-description">
                    Rendered against a real customer, because a preview with empty variables is an empty shell.
                </p>
            </div>

            <div class="card-content space-y-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[14rem] flex-1">
                        <label for="preview_user" class="label">Customer</label>
                        <input id="preview_user" type="number" x-model="userId" placeholder="User id (blank = most recent)"
                               class="input mt-1.5">
                    </div>

                    <button type="button" class="btn btn-outline" @click="loadPreview()" :disabled="loading">
                        <span x-text="loading ? 'Rendering…' : 'Preview'"></span>
                    </button>

                    @if(auth()->user()->hasPermission('send_lifecycle') || auth()->user()->hasPermission('manage_lifecycle'))
                        <button type="button" class="btn btn-ghost" @click="sendTest()" :disabled="sending">
                            <span x-text="sending ? 'Sending…' : 'Send a test'"></span>
                        </button>
                    @endif
                </div>

                <p class="text-xs text-muted-foreground" x-show="message" x-text="message"></p>

                <template x-if="unknown.length">
                    <div class="alert alert-warning">
                        <p class="text-sm">
                            Unknown variables:
                            <span class="font-mono" x-text="unknown.join(', ')"></span>
                            — these will be left as written.
                        </p>
                    </div>
                </template>

                <div class="rounded-lg border border-border bg-surface-muted p-4">
                    <p class="text-xs text-muted-foreground">Subject</p>
                    <p class="text-sm font-medium" x-text="subject || '—'"></p>
                </div>

                <iframe x-ref="frame" class="h-[520px] w-full rounded-lg border border-border bg-white" title="Email preview"></iframe>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
    /*
     * Preview and test.
     *
     * The preview is rendered by the server, not in the browser: the sanitising and the
     * variable substitution both happen in PHP, and a client-side preview would be a
     * second implementation that could disagree with what actually gets sent. This only
     * displays what the endpoint returns.
     */
    document.addEventListener('alpine:init', () => {
        Alpine.data('lifecycleTemplate', (config) => ({
            userId: '',
            subject: '',
            unknown: [],
            loading: false,
            sending: false,
            message: '',

            async loadPreview() {
                if (! config.previewUrl) {
                    this.message = 'Save the template first, then preview it.';
                    return;
                }

                this.loading = true;
                this.message = '';

                try {
                    const url = new URL(config.previewUrl, window.location.origin);
                    if (this.userId) {
                        url.searchParams.set('user_id', this.userId);
                    }

                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const data = await response.json();

                    this.subject = data.subject;
                    this.unknown = data.unknown_variables || [];
                    this.$refs.frame.srcdoc = data.html;
                } catch (error) {
                    this.message = 'Could not render the preview.';
                } finally {
                    this.loading = false;
                }
            },

            async sendTest() {
                if (! config.testUrl) {
                    this.message = 'Save the template first, then send a test.';
                    return;
                }

                if (! this.userId) {
                    this.message = 'Enter a customer id to send a test to.';
                    return;
                }

                this.sending = true;
                this.message = '';

                try {
                    const response = await fetch(config.testUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({ user_id: this.userId }),
                    });

                    this.message = response.ok
                        ? 'Test queued. Check the delivery log for the outcome.'
                        : 'The test could not be sent.';
                } catch (error) {
                    this.message = 'The test could not be sent.';
                } finally {
                    this.sending = false;
                }
            },
        }));
    });
</script>
@endpush
@endsection
