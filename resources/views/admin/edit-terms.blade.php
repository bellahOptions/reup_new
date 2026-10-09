@extends('admin.layouts.app')

@section('title', 'Legal documents')
@section('page-title', 'Legal documents')
@section('page-description', 'Edit the Terms of Service and Privacy Policy published to customers.')

@section('page-actions')
    <span class="badge badge-primary">
        <x-icon name="shield-check" class="h-3 w-3" />
        Super admin only
    </span>
@endsection

@section('content')
<div class="space-y-6" x-data="legalDocuments" x-init="init()">

    {{-- ======================= Document type tabs ===================== --}}
    <div class="card overflow-hidden">
        <div class="flex flex-col sm:flex-row">
            @foreach(['terms' => ['label' => 'Terms of Service', 'icon' => 'document-text'], 'privacy' => ['label' => 'Privacy Policy', 'icon' => 'lock-closed']] as $key => $tab)
                <button type="button"
                        id="tab-{{ $key }}"
                        data-document-tab="{{ $key }}"
                        @click="switchDocument('{{ $key }}')"
                        class="flex flex-1 items-center justify-center gap-2 border-b-2 px-6 py-4 text-sm font-medium transition-colors"
                        :class="documentType === '{{ $key }}'
                            ? 'border-brand-500 bg-brand-50 text-brand-700'
                            : 'border-transparent text-muted-foreground hover:bg-ink-50 hover:text-foreground'">
                    <x-icon name="{{ $tab['icon'] }}" class="h-4 w-4" />
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        {{-- =========================== Editor ========================= --}}
        <div class="space-y-4 lg:col-span-3">
            <div class="card overflow-hidden">
                <div class="card-header flex-row flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="card-title" id="editor-title">Terms of Service</h2>
                        <p class="card-description" id="editor-subtitle">
                            Last updated: {{ $terms?->updated_at?->format('M d, Y h:i A') ?? 'Never published' }}
                            @if($terms?->updatedBy)
                                by <span class="font-medium">{{ $terms->updatedBy?->name }}</span>
                            @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" class="btn btn-outline btn-sm" @click="previewDocument()">
                            <x-icon name="eye" class="h-4 w-4" />
                            Preview
                        </button>
                        <button type="button" id="saveBtn" class="btn btn-primary btn-sm" @click="saveDocument()">
                            <x-icon name="check" class="h-4 w-4" />
                            Save changes
                        </button>
                    </div>
                </div>

                <div class="card-content">
                    <form id="documentForm">
                        @csrf
                        <input type="hidden" id="documentType" name="type" value="terms">
                        <input type="hidden" id="documentContent" name="content">

                        {{-- Quill toolbar --}}
                        <div id="toolbar" class="rounded-t-lg border border-border bg-surface p-2">
                            <span class="ql-formats">
                                <select class="ql-header">
                                    <option value="1">Heading 1</option>
                                    <option value="2">Heading 2</option>
                                    <option value="3">Heading 3</option>
                                    <option selected>Normal</option>
                                </select>
                                <select class="ql-font">
                                    <option selected>Sans Serif</option>
                                    <option value="serif">Serif</option>
                                    <option value="monospace">Monospace</option>
                                </select>
                                <select class="ql-size">
                                    <option value="small">Small</option>
                                    <option selected>Normal</option>
                                    <option value="large">Large</option>
                                    <option value="huge">Huge</option>
                                </select>
                            </span>

                            <span class="ql-formats">
                                <button class="ql-bold"></button>
                                <button class="ql-italic"></button>
                                <button class="ql-underline"></button>
                                <button class="ql-strike"></button>
                            </span>

                            <span class="ql-formats">
                                <select class="ql-color"></select>
                                <select class="ql-background"></select>
                            </span>

                            <span class="ql-formats">
                                <button class="ql-list" value="ordered"></button>
                                <button class="ql-list" value="bullet"></button>
                                <button class="ql-indent" value="-1"></button>
                                <button class="ql-indent" value="+1"></button>
                            </span>

                            <span class="ql-formats">
                                <select class="ql-align"></select>
                            </span>

                            <span class="ql-formats">
                                <button class="ql-link"></button>
                                <button class="ql-image"></button>
                                <button class="ql-video"></button>
                            </span>

                            <span class="ql-formats">
                                <button class="ql-blockquote"></button>
                                <button class="ql-code-block"></button>
                            </span>

                            <span class="ql-formats">
                                <button class="ql-clean"></button>
                            </span>
                        </div>

                        <div id="editor" class="quill-editor-surface overflow-hidden rounded-b-lg border-x border-b border-border bg-surface">
                            {!! $terms?->content ?: '<p class="text-muted-foreground">This document has not been published yet. Write it below and save.</p>' !!}
                        </div>

                        <div class="mt-3 flex items-center justify-between text-xs tabular-nums text-muted-foreground">
                            <span id="charCount">0 characters</span>
                            <span id="wordCount">0 words</span>
                        </div>
                    </form>
                </div>

                <div class="card-footer items-start gap-3">
                    <x-icon name="information-circle" class="mt-0.5 h-5 w-5 text-ink-400" />
                    <div>
                        <p class="text-sm font-medium">Auto-save is disabled</p>
                        <p class="mt-1 text-xs text-muted-foreground">Changes must be saved manually. Select "Save changes" when you are finished editing.</p>
                    </div>
                </div>
            </div>

            <div id="statusMessage" class="hidden"></div>
        </div>

        {{-- =========================== Sidebar ======================== --}}
        <div class="space-y-6 lg:col-span-1">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title flex items-center gap-2">
                        <x-icon name="bolt" class="h-4 w-4 text-ink-500" />
                        Quick actions
                    </h2>
                    <p class="card-description">Insert a ready-made section at the cursor.</p>
                </div>

                <div class="card-content space-y-2">
                    <button type="button" class="btn btn-outline w-full justify-start" @click="insertTemplate('introduction')">
                        <x-icon name="document-text" class="h-4 w-4" />
                        Insert introduction
                    </button>
                    <button type="button" class="btn btn-outline w-full justify-start" @click="insertTemplate('acceptance')">
                        <x-icon name="document-check" class="h-4 w-4" />
                        Insert acceptance clause
                    </button>
                    <button type="button" class="btn btn-outline w-full justify-start" @click="insertTemplate('liability')">
                        <x-icon name="scale" class="h-4 w-4" />
                        Insert liability section
                    </button>
                    <button type="button" class="btn btn-outline w-full justify-start" @click="insertTemplate('contact')">
                        <x-icon name="envelope" class="h-4 w-4" />
                        Insert contact info
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title flex items-center gap-2">
                        <x-icon name="information-circle" class="h-4 w-4 text-ink-500" />
                        Document info
                    </h2>
                </div>

                <div class="card-content space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3 border-b border-border pb-3">
                        <span class="stat-label">Current version</span>
                        <span class="font-medium" id="versionDate">
                            {{ $terms?->version_date?->format('M d, Y') ?? 'Not set' }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-b border-border pb-3">
                        <span class="stat-label">Last modified</span>
                        <span class="font-medium" id="lastModified">
                            {{ $terms?->updated_at?->diffForHumans() ?? 'Never' }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-b border-border pb-3">
                        <span class="stat-label">Modified by</span>
                        <span class="font-medium" id="modifiedBy">
                            {{ $terms?->updatedBy?->name ?? '—' }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="stat-label">Status</span>
                        <span class="badge {{ $terms?->is_active ? 'badge-success' : 'badge-neutral' }}">
                            {{ $terms?->is_active ? 'Active' : 'Not published' }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title flex items-center gap-2">
                        <x-icon name="light-bulb" class="h-4 w-4 text-ink-500" />
                        Editing tips
                    </h2>
                </div>

                <div class="card-content">
                    <ul class="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                        <li>Use headings to organise sections clearly.</li>
                        <li>Keep the language simple and unambiguous.</li>
                        <li>Preview the document before saving.</li>
                        <li>Update the version date when the terms change.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- ========================= Preview modal ======================== --}}
    <div x-show="previewOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/50 p-4"
         @keydown.escape.window="closePreview()"
         @click.self="closePreview()">
        <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-border bg-surface shadow-overlay"
             role="dialog" aria-modal="true" aria-labelledby="previewTitle">
            <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                <h3 id="previewTitle" class="card-title">Document preview</h3>
                <button type="button" class="btn btn-ghost btn-icon" aria-label="Close preview" @click="closePreview()">
                    <x-icon name="x-mark" class="h-5 w-5" />
                </button>
            </div>

            <div id="previewContent" class="legal-prose scrollbar-slim max-w-none overflow-y-auto p-6"></div>

            <div class="border-t border-border p-4">
                <button type="button" class="btn btn-outline w-full" @click="closePreview()">Close preview</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
{{-- Quill is the one remaining third-party runtime dependency in this project.
     It is loaded on this page only, and only for administrators with the
     `manage_settings` permission, because the legal-document editor needs a
     real rich-text surface (bold, headings, lists, links) that the design
     system does not provide.

     The version is pinned deliberately. To remove the dependency entirely,
     vendor this file into public/vendor/quill/ and point the href at it —
     an SRI hash cannot be added safely until it is self-hosted, since a
     mismatch would silently break the editor. --}}
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
@endpush

@push('scripts')
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script>
    // ---------------------------------------------------------------
    // Legal document editor
    // ---------------------------------------------------------------
    // Quill is unchanged and still drives #editor. What changed is the
    // wiring: tab switching, the preview modal and the quick-action
    // templates were `onclick="..."` globals toggling Tailwind classes by
    // hand, and now run through Alpine. Element ids (#editor, #toolbar,
    // #saveBtn, #statusMessage, #charCount, #wordCount, #documentType,
    // #versionDate, #lastModified, #modifiedBy, #previewContent) are kept
    // verbatim so the save/load/preview code below still finds them.
    let quill;
    let currentDocumentType = 'terms';

    const documents = {
        terms: {
            content: @js($terms?->content ?? ''),
            title: 'Terms of Service',
            subtitle: 'Legal agreement between users and our platform',
        },
        privacy: {
            content: '',
            title: 'Privacy Policy',
            subtitle: 'How we collect, use and protect user data',
        },
    };

    document.addEventListener('alpine:init', () => {
        Alpine.data('legalDocuments', () => ({
            documentType: 'terms',
            previewOpen: false,

            init() {
                this.$nextTick(() => this.initEditor());
            },

            initEditor() {
                const target = document.getElementById('editor');
                if (!target || typeof Quill === 'undefined') return;

                quill = new Quill('#editor', {
                    theme: 'snow',
                    modules: { toolbar: '#toolbar' },
                    placeholder: 'Start writing your legal document here...',
                });

                if (documents.terms.content) {
                    quill.root.innerHTML = documents.terms.content;
                }

                quill.on('text-change', () => this.updateCounts());
                this.updateCounts();
            },

            switchDocument(type) {
                if (!quill) return;

                documents[currentDocumentType].content = quill.root.innerHTML;
                currentDocumentType = type;
                this.documentType = type;

                quill.root.innerHTML = documents[type].content || '<p>Start writing your ' + type + ' here...</p>';

                document.getElementById('editor-title').textContent = documents[type].title;
                document.getElementById('editor-subtitle').textContent = documents[type].subtitle;
                document.getElementById('documentType').value = type;

                this.updateCounts();
            },

            updateCounts() {
                if (!quill) return;

                const text = quill.getText().trim();
                const words = text.split(/\s+/).filter((word) => word.length > 0).length;

                document.getElementById('charCount').textContent = text.length.toLocaleString() + ' characters';
                document.getElementById('wordCount').textContent = words.toLocaleString() + ' words';
            },

            async saveDocument() {
                if (!quill) return;

                const saveBtn = document.getElementById('saveBtn');
                const originalText = saveBtn.innerHTML;
                const content = quill.root.innerHTML;

                if (content.trim().length < 50) {
                    this.showMessage('error', 'Content is too short. Please add more content (minimum 50 characters).');
                    return;
                }

                saveBtn.disabled = true;
                saveBtn.innerHTML = 'Saving...';

                try {
                    const response = await fetch('{{ route("admin.terms.update") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            type: currentDocumentType,
                            content: content,
                        }),
                    });

                    const data = await response.json();

                    if (!data.success) {
                        throw new Error(data.message || 'Failed to save the document.');
                    }

                    this.showMessage('success', data.message);

                    if (data.data) {
                        document.getElementById('lastModified').textContent = data.data.updated_at;
                        document.getElementById('modifiedBy').textContent = data.data.updated_by;
                        document.getElementById('versionDate').textContent = data.data.version_date;
                        document.getElementById('editor-subtitle').textContent =
                            'Last updated: ' + data.data.updated_at + ' by ' + data.data.updated_by;
                    }

                    documents[currentDocumentType].content = content;
                } catch (error) {
                    this.showMessage('error', error.message || 'Failed to save the document. Please try again.');
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalText;
                }
            },

            showMessage(type, message) {
                const statusEl = document.getElementById('statusMessage');
                statusEl.className = type === 'success'
                    ? 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700'
                    : 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700';

                statusEl.textContent = message;
                statusEl.classList.remove('hidden');

                setTimeout(() => statusEl.classList.add('hidden'), 5000);
                statusEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            },

            previewDocument() {
                if (!quill) return;

                document.getElementById('previewContent').innerHTML = quill.root.innerHTML;
                this.previewOpen = true;
            },

            closePreview() {
                this.previewOpen = false;
            },

            insertTemplate(type) {
                if (!quill) return;

                const templates = {
                    introduction: `<h2>Introduction</h2>
<p>Welcome to {{ config('app.name') }}. These Terms of Service govern your use of our platform and services. By accessing or using our services, you agree to be bound by these terms.</p>
<p>Please read these terms carefully before using our services. If you do not agree with any part of these terms, you may not access or use our services.</p>`,

                    acceptance: `<h2>Acceptance of Terms</h2>
<p>By creating an account, accessing, or using our services, you acknowledge that you have read, understood, and agree to be bound by these Terms of Service and our Privacy Policy.</p>
<p>We reserve the right to update these terms at any time. Continued use of our services after changes constitutes acceptance of the modified terms.</p>`,

                    liability: `<h2>Limitation of Liability</h2>
<p>To the maximum extent permitted by law, {{ config('app.name') }} shall not be liable for any indirect, incidental, special, consequential, or punitive damages, or any loss of profits or revenues, whether incurred directly or indirectly, or any loss of data, use, goodwill, or other intangible losses.</p>
<p>In no event shall our total liability exceed the amount paid by you to us in the twelve (12) months preceding the claim.</p>`,

                    contact: `<h2>Contact Information</h2>
<p>If you have any questions about these Terms of Service, please contact us:</p>
<ul>
    <li><strong>Email:</strong> reup.bellahoptions@gmail.com</li>
    <li><strong>Phone:</strong> +234 903 141 2354</li>
    <li><strong>Address:</strong> Atan Ota, Ogun State, Nigeria</li>
</ul>
<p>We aim to respond to all inquiries within 1-2 business days.</p>`,
                };

                if (!templates[type]) return;

                const range = quill.getSelection();

                if (range) {
                    quill.clipboard.dangerouslyPasteHTML(range.index, templates[type]);
                } else {
                    quill.clipboard.dangerouslyPasteHTML(quill.getLength(), templates[type]);
                }

                this.updateCounts();
            },
        }));
    });

    // Keep the editor buffer in step with the live editor between saves.
    setInterval(function () {
        if (quill) {
            documents[currentDocumentType].content = quill.root.innerHTML;
        }
    }, 30000);
</script>
@endpush
