@extends('admin.layouts.app')

@section('title', 'Manage Terms of Service')

@section('content')
<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                        <span class="text-2xl">📜</span>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Manage Legal Documents</h1>
                        <p class="text-gray-600 mt-1">Edit Terms of Service and Privacy Policy</p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <span class="px-3 py-1 bg-green-100 text-green-800 text-sm font-semibold rounded-full">
                        👑 Super Admin Only
                    </span>
                </div>
            </div>
        </div>

        <!-- Document Type Tabs -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
            <div class="border-b border-gray-200">
                <nav class="flex -mb-px">
                    <button type="button" 
                            onclick="switchDocument('terms')"
                            id="tab-terms"
                            class="document-tab active flex-1 py-4 px-6 text-center font-semibold transition-colors">
                        <span class="text-lg mr-2">📋</span>
                        Terms of Service
                    </button>
                    <button type="button" 
                            onclick="switchDocument('privacy')"
                            id="tab-privacy"
                            class="document-tab flex-1 py-4 px-6 text-center font-semibold transition-colors">
                        <span class="text-lg mr-2">🔒</span>
                        Privacy Policy
                    </button>
                </nav>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Main Editor -->
            <div class="lg:col-span-3">
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                    <!-- Editor Header -->
                    <div class="border-b border-gray-200 p-6 bg-gradient-to-r from-green-50 to-gray-50">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-xl font-bold text-gray-900" id="editor-title">Terms of Service</h2>
                                <p class="text-sm text-gray-600 mt-1" id="editor-subtitle">
                                    Last updated: {{ $terms->updated_at ? $terms->updated_at->format('M d, Y h:i A') : 'Never' }}
                                    @if($terms->updatedBy)
                                        by <span class="font-semibold">{{ $terms->updatedBy->name }}</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <button type="button" 
                                        onclick="previewDocument()"
                                        class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white font-semibold rounded-lg transition-colors flex items-center">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    Preview
                                </button>
                                <button type="button" 
                                        onclick="saveDocument()"
                                        id="saveBtn"
                                        class="px-4 py-2 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Save Changes
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Quill Editor -->
                    <div class="p-6">
                        <form id="documentForm">
                            @csrf
                            <input type="hidden" id="documentType" name="type" value="terms">
                            <input type="hidden" id="documentContent" name="content">
                            
                            <!-- Quill Toolbar -->
                            <div id="toolbar" class="bg-gray-50 border border-gray-300 rounded-t-lg p-2">
                                <!-- Text Formatting -->
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
                                
                                <!-- Text Style -->
                                <span class="ql-formats">
                                    <button class="ql-bold"></button>
                                    <button class="ql-italic"></button>
                                    <button class="ql-underline"></button>
                                    <button class="ql-strike"></button>
                                </span>
                                
                                <!-- Colors -->
                                <span class="ql-formats">
                                    <select class="ql-color"></select>
                                    <select class="ql-background"></select>
                                </span>
                                
                                <!-- Lists -->
                                <span class="ql-formats">
                                    <button class="ql-list" value="ordered"></button>
                                    <button class="ql-list" value="bullet"></button>
                                    <button class="ql-indent" value="-1"></button>
                                    <button class="ql-indent" value="+1"></button>
                                </span>
                                
                                <!-- Alignment -->
                                <span class="ql-formats">
                                    <select class="ql-align"></select>
                                </span>
                                
                                <!-- Links & Media -->
                                <span class="ql-formats">
                                    <button class="ql-link"></button>
                                    <button class="ql-image"></button>
                                    <button class="ql-video"></button>
                                </span>
                                
                                <!-- Formatting -->
                                <span class="ql-formats">
                                    <button class="ql-blockquote"></button>
                                    <button class="ql-code-block"></button>
                                </span>
                                
                                <!-- Clear -->
                                <span class="ql-formats">
                                    <button class="ql-clean"></button>
                                </span>
                            </div>
                            
                            <!-- Quill Editor Container -->
                            <div id="editor" class="bg-white border-x border-b border-gray-300 rounded-b-lg" style="min-height: 500px;">
                                {!! $terms->content ?? '<p>Start editing your terms of service here...</p>' !!}
                            </div>

                            <!-- Character Count -->
                            <div class="mt-3 flex items-center justify-between text-sm text-gray-600">
                                <span id="charCount">0 characters</span>
                                <span id="wordCount">0 words</span>
                            </div>
                        </form>
                    </div>

                    <!-- Save Notice -->
                    <div class="border-t border-gray-200 p-4 bg-gray-50">
                        <div class="flex items-start space-x-3">
                            <svg class="w-5 h-5 text-gray-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div class="flex-1">
                                <p class="text-sm text-gray-700 font-semibold">Auto-save Disabled</p>
                                <p class="text-xs text-gray-600 mt-1">Changes must be saved manually. Click "Save Changes" when you're done editing.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Message -->
                <div id="statusMessage" class="hidden mt-4"></div>
            </div>

            <!-- Sidebar -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Quick Actions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                        <span class="text-xl mr-2">⚡</span>
                        Quick Actions
                    </h3>
                    
                    <div class="space-y-3">
                        <button type="button" 
                                onclick="insertTemplate('introduction')"
                                class="w-full text-left px-4 py-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors text-sm font-medium text-gray-700">
                            <span class="mr-2">📝</span> Insert Introduction
                        </button>
                        
                        <button type="button" 
                                onclick="insertTemplate('acceptance')"
                                class="w-full text-left px-4 py-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors text-sm font-medium text-gray-700">
                            <span class="mr-2">✅</span> Insert Acceptance Clause
                        </button>
                        
                        <button type="button" 
                                onclick="insertTemplate('liability')"
                                class="w-full text-left px-4 py-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors text-sm font-medium text-gray-700">
                            <span class="mr-2">⚖️</span> Insert Liability Section
                        </button>
                        
                        <button type="button" 
                                onclick="insertTemplate('contact')"
                                class="w-full text-left px-4 py-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors text-sm font-medium text-gray-700">
                            <span class="mr-2">📧</span> Insert Contact Info
                        </button>
                    </div>
                </div>

                <!-- Document Info -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                        <span class="text-xl mr-2">ℹ️</span>
                        Document Info
                    </h3>
                    
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Current Version</span>
                            <span class="font-semibold text-gray-900" id="versionDate">
                                {{ $terms->version_date ? $terms->version_date->format('M d, Y') : 'N/A' }}
                            </span>
                        </div>
                        
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Last Modified</span>
                            <span class="font-semibold text-gray-900" id="lastModified">
                                {{ $terms->updated_at ? $terms->updated_at->diffForHumans() : 'Never' }}
                            </span>
                        </div>
                        
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Modified By</span>
                            <span class="font-semibold text-gray-900" id="modifiedBy">
                                {{ $terms->updatedBy ? $terms->updatedBy->name : 'N/A' }}
                            </span>
                        </div>
                        
                        <div class="flex justify-between py-2">
                            <span class="text-gray-600">Status</span>
                            <span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded-full">
                                {{ $terms->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Tips -->
                <div class="bg-gradient-to-br from-gray-50 to-green-50 rounded-xl border border-gray-200 p-6">
                    <h3 class="font-bold text-gray-900 mb-3 flex items-center">
                        <span class="text-xl mr-2">💡</span>
                        Editing Tips
                    </h3>
                    
                    <ul class="space-y-2 text-sm text-gray-700">
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Use headings to organize sections clearly</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Keep language simple and clear</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Preview before saving changes</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Update version date when making changes</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-4xl w-full max-h-[90vh] overflow-hidden shadow-2xl">
        <div class="border-b border-gray-200 p-6 flex items-center justify-between bg-gradient-to-r from-green-50 to-gray-50">
            <h3 class="text-xl font-bold text-gray-900">Document Preview</h3>
            <button onclick="closePreview()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="previewContent" class="p-8 overflow-y-auto max-h-[calc(90vh-140px)] prose max-w-none">
            <!-- Preview content will be inserted here -->
        </div>
        <div class="border-t border-gray-200 p-4 bg-gray-50">
            <button onclick="closePreview()" class="w-full bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-4 rounded-lg">
                Close Preview
            </button>
        </div>
    </div>
</div>
@endsection

@push('styles')
<!-- Quill.js Styles -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<style>
    /* Custom Quill Styles */
    .ql-container {
        font-size: 16px;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }
    
    .ql-editor {
        min-height: 500px;
        max-height: 600px;
        overflow-y: auto;
    }
    
    .ql-editor h1 {
        font-size: 2em;
        font-weight: bold;
        margin-bottom: 0.5em;
    }
    
    .ql-editor h2 {
        font-size: 1.5em;
        font-weight: bold;
        margin-bottom: 0.5em;
    }
    
    .ql-editor h3 {
        font-size: 1.25em;
        font-weight: bold;
        margin-bottom: 0.5em;
    }
    
    /* Tab Styles */
    .document-tab {
        border-bottom: 3px solid transparent;
        color: #6b7280;
    }
    
    .document-tab:hover {
        color: #374151;
        background-color: #f9fafb;
    }
    
    .document-tab.active {
        color: #7c3aed;
        border-bottom-color: #7c3aed;
        background-color: #faf5ff;
    }
    
    /* Preview Modal Styles */
    .prose {
        color: #374151;
    }
    
    .prose h1 {
        color: #111827;
        font-size: 2em;
        font-weight: 700;
        margin-bottom: 1em;
    }
    
    .prose h2 {
        color: #1f2937;
        font-size: 1.5em;
        font-weight: 600;
        margin-top: 2em;
        margin-bottom: 1em;
    }
    
    .prose h3 {
        color: #374151;
        font-size: 1.25em;
        font-weight: 600;
        margin-top: 1.5em;
        margin-bottom: 0.75em;
    }
    
    .prose p {
        margin-bottom: 1em;
        line-height: 1.75;
    }
    
    .prose ul, .prose ol {
        margin-bottom: 1em;
        padding-left: 2em;
    }
    
    .prose li {
        margin-bottom: 0.5em;
    }
</style>
@endpush

@push('scripts')
<!-- Quill.js -->
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script>
let quill;
let currentDocumentType = 'terms';

// Document data storage
const documents = {
    terms: {
        content: `{!! addslashes($terms->content ?? '') !!}`,
        title: 'Terms of Service',
        subtitle: 'Legal agreement between users and our platform'
    },
    privacy: {
        content: '', // Will be loaded via AJAX
        title: 'Privacy Policy',
        subtitle: 'How we collect, use, and protect user data'
    }
};

// Initialize Quill Editor
document.addEventListener('DOMContentLoaded', function() {
    quill = new Quill('#editor', {
        theme: 'snow',
        modules: {
            toolbar: '#toolbar'
        },
        placeholder: 'Start writing your legal document here...'
    });
    
    // Load initial content
    if (documents.terms.content) {
        quill.root.innerHTML = documents.terms.content;
    }
    
    // Update character and word count
    quill.on('text-change', function() {
        updateCounts();
    });
    
    // Initial count update
    updateCounts();
    
    // Load privacy policy content
    loadPrivacyContent();
});

/**
 * Load privacy policy content from server
 * @returns {Promise<boolean>} Success status
 */
async function loadPrivacyContent() {
    try {
        const response = await fetch('/admin/admin/terms-of-service/get-privacy', {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const data = await response.json();
        
        if (!data.success || !data.content) {
            throw new Error(data.message || 'Invalid response');
        }
        
        documents.privacy.content = data.content;
        return true;
        
    } catch (error) {
        console.error('Failed to load privacy policy:', error.message);
        
        // Set graceful fallback
        documents.privacy.content = `
            <div class="text-center py-8 px-4 text-gray-600">
                <h3 class="text-xl font-semibold mb-2">Privacy Policy</h3>
                <p>Content is currently being updated.</p>
                <p class="text-sm mt-4">Please check back shortly.</p>
            </div>
        `;
        
        return false;
    }
}

// Initialize when needed
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('[data-privacy-content]')) {
        loadPrivacyContent();
    }
});

// Switch between documents
function switchDocument(type) {
    // Save current content before switching
    documents[currentDocumentType].content = quill.root.innerHTML;
    
    // Update current type
    currentDocumentType = type;
    
    // Update tabs
    document.querySelectorAll('.document-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    document.getElementById('tab-' + type).classList.add('active');
    
    // Load content
    quill.root.innerHTML = documents[type].content || '<p>Start writing your ' + type + ' here...</p>';
    
    // Update UI
    document.getElementById('editor-title').textContent = documents[type].title;
    document.getElementById('editor-subtitle').textContent = documents[type].subtitle;
    document.getElementById('documentType').value = type;
    
    updateCounts();
}

// Update character and word counts
function updateCounts() {
    const text = quill.getText();
    const charCount = text.trim().length;
    const wordCount = text.trim().split(/\s+/).filter(word => word.length > 0).length;
    
    document.getElementById('charCount').textContent = charCount.toLocaleString() + ' characters';
    document.getElementById('wordCount').textContent = wordCount.toLocaleString() + ' words';
}

// Save document
async function saveDocument() {
    const saveBtn = document.getElementById('saveBtn');
    const originalText = saveBtn.innerHTML;
    
    // Get content
    const content = quill.root.innerHTML;
    
    if (content.trim().length < 50) {
        showMessage('error', 'Content is too short. Please add more content (minimum 50 characters).');
        return;
    }
    
    // Show loading
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...';
    
    try {
        const response = await fetch('{{ route("terms.update") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                type: currentDocumentType,
                content: content
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showMessage('success', data.message);
            
            // Update document info
            if (data.data) {
                document.getElementById('lastModified').textContent = data.data.updated_at;
                document.getElementById('modifiedBy').textContent = data.data.updated_by;
                document.getElementById('versionDate').textContent = data.data.version_date;
                document.getElementById('editor-subtitle').textContent = 
                    'Last updated: ' + data.data.updated_at + ' by ' + data.data.updated_by;
            }
            
            // Save to local storage
            documents[currentDocumentType].content = content;
        } else {
            throw new Error(data.message || 'Failed to save document');
        }
    } catch (error) {
        console.error('Save error:', error);
        showMessage('error', error.message || 'Failed to save document. Please try again.');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

// Show status message
function showMessage(type, message) {
    const statusEl = document.getElementById('statusMessage');
    statusEl.className = type === 'success' 
        ? 'p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg'
        : 'p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg';
    
    statusEl.innerHTML = `
        <div class="flex items-center">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                ${type === 'success' 
                    ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>'
                    : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>'}
            </svg>
            <span><strong>${type === 'success' ? 'Success!' : 'Error:'}</strong> ${message}</span>
        </div>
    `;
    statusEl.classList.remove('hidden');
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        statusEl.classList.add('hidden');
    }, 5000);
    
    // Scroll to message
    statusEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Preview document
function previewDocument() {
    const content = quill.root.innerHTML;
    document.getElementById('previewContent').innerHTML = content;
    document.getElementById('previewModal').classList.remove('hidden');
}

// Close preview
function closePreview() {
    document.getElementById('previewModal').classList.add('hidden');
}

// Insert templates
function insertTemplate(type) {
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
<p>We aim to respond to all inquiries within 1-2 business days.</p>`
    };
    
    if (templates[type]) {
        // Get current selection
        const range = quill.getSelection();
        if (range) {
            // Insert at cursor
            quill.clipboard.dangerouslyPasteHTML(range.index, templates[type]);
        } else {
            // Insert at end
            const length = quill.getLength();
            quill.clipboard.dangerouslyPasteHTML(length, templates[type]);
        }
        
        // Update counts
        updateCounts();
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+S or Cmd+S to save
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveDocument();
    }
    
    // Esc to close preview
    if (e.key === 'Escape') {
        const previewModal = document.getElementById('previewModal');
        if (!previewModal.classList.contains('hidden')) {
            closePreview();
        }
    }
});

// Close preview when clicking outside
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePreview();
    }
});

// Auto-save content periodically
setInterval(function() {
    if (quill) {
        documents[currentDocumentType].content = quill.root.innerHTML;
    }
}, 30000); // Auto-save every 30 seconds

// Format document content before saving
function formatContent(content) {
    // Remove empty paragraphs
    content = content.replace(/<p><br><\/p>/g, '');
    content = content.replace(/<p>\s*<\/p>/g, '');
    
    // Trim whitespace
    content = content.trim();
    
    return content;
}

// Load document from server
async function loadDocument(type) {
    try {
        const response = await fetch(`/admin/terms-of-service/get/${type}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });
        
        if (response.ok) {
            const data = await response.json();
            if (data.success) {
                documents[type].content = data.content || '';
                
                // Update UI info
                if (data.document_info) {
                    document.getElementById('lastModified').textContent = data.document_info.updated_at;
                    document.getElementById('modifiedBy').textContent = data.document_info.updated_by;
                    document.getElementById('versionDate').textContent = data.document_info.version_date;
                }
                
                return true;
            }
        }
    } catch (error) {
        console.error(`Error loading ${type}:`, error);
    }
    
    return false;
}

// Export document
function exportDocument(format = 'html') {
    const content = quill.root.innerHTML;
    const title = documents[currentDocumentType].title;
    const date = new Date().toISOString().split('T')[0];
    
    if (format === 'html') {
        const htmlContent = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>${title}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1, h2, h3 { color: #333; }
        p { margin-bottom: 1em; }
        .header { text-align: center; border-bottom: 2px solid #ddd; padding-bottom: 20px; margin-bottom: 40px; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>${title}</h1>
        <p>Last Updated: ${date}</p>
    </div>
    ${content}
    <div class="footer">
        <p>© ${new Date().getFullYear()} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>
</html>`;
        
        // Download HTML file
        const blob = new Blob([htmlContent], { type: 'text/html' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${title.toLowerCase().replace(/\s+/g, '-')}-${date}.html`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showMessage('success', 'Document exported as HTML');
        
    } else if (format === 'text') {
        // Convert HTML to plain text
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = content;
        const textContent = tempDiv.textContent || tempDiv.innerText || '';
        
        const blob = new Blob([textContent], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${title.toLowerCase().replace(/\s+/g, '-')}-${date}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showMessage('success', 'Document exported as Text');
    }
}

// Copy to clipboard
function copyToClipboard() {
    const content = quill.root.innerHTML;
    
    // Create a temporary element to copy from
    const tempElement = document.createElement('div');
    tempElement.innerHTML = content;
    document.body.appendChild(tempElement);
    
    const range = document.createRange();
    range.selectNode(tempElement);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            showMessage('success', 'Document copied to clipboard!');
        } else {
            throw new Error('Copy command failed');
        }
    } catch (err) {
        console.error('Failed to copy:', err);
        showMessage('error', 'Failed to copy to clipboard');
    }
    
    document.body.removeChild(tempElement);
    window.getSelection().removeAllRanges();
}

// Initialize with server content
async function initializeDocuments() {
    try {
        // Load both documents
        const [termsLoaded, privacyLoaded] = await Promise.all([
            loadDocument('terms'),
            loadDocument('privacy')
        ]);
        
        if (!termsLoaded) {
            showMessage('warning', 'Could not load Terms of Service from server');
        }
        
        if (!privacyLoaded) {
            showMessage('warning', 'Could not load Privacy Policy from server');
        }
        
    } catch (error) {
        console.error('Error initializing documents:', error);
    }
}

// Call initialization
initializeDocuments();
</script>
@endpush