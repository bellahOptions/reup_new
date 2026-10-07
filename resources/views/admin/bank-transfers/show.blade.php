{{-- Loaded over AJAX into the review modal on admin/bank-transfers/index.
     Form submissions are handled by the delegated listener on #modalContent
     in the parent view, so this partial intentionally contains no scripts. --}}
@php
    $bankDetails = config('wallet.bank');

    /*
     * `meta` arrives already decoded: Transactions casts it with
     * `'meta' => 'array'`, and BankTransferController::show passes
     * `$transfer->meta ?? []`. Decoding it again threw
     * "json_decode(): Argument #1 ($json) must be of type string, array given",
     * which took the whole review modal down with a 500.
     *
     * The fallback keeps this working if the column is ever null.
     */
    $meta = is_array($meta ?? null) ? $meta : ($transfer->meta ?? []);

    $statusBadge = match ($transfer->status) {
        'success' => 'badge-success',
        'pending', 'verifying' => 'badge-warning',
        'failed' => 'badge-destructive',
        default => 'badge-neutral',
    };

    $proofPath = $meta['proof_path'] ?? null;
    $proofUrl = $proofPath ? Storage::url($proofPath) : null;

    $extension = $proofUrl ? strtolower(pathinfo($proofUrl, PATHINFO_EXTENSION)) : null;
    $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    $isPdf = $extension === 'pdf';
@endphp

<div class="space-y-6" id="bankTransferDetails">

    {{-- ====================== Transfer information ==================== --}}
    <div class="card">
        <div class="card-header flex-row items-start justify-between gap-3">
            <div>
                <h2 class="card-title">Transfer details</h2>
                <p class="card-description">
                    Reference <span class="font-mono text-xs">{{ $transfer->reference }}</span>
                </p>
            </div>
            <span class="badge {{ $statusBadge }}">{{ ucfirst($transfer->status) }}</span>
        </div>

        <div class="card-content space-y-6">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="stat-label">Amount</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">&#8358;{{ number_format($transfer->amount, 2) }}</dd>
                </div>
                <div>
                    <dt class="stat-label">Date</dt>
                    <dd class="mt-1 text-sm tabular-nums">{{ $transfer->created_at->format('M d, Y \a\t h:i A') }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="stat-label">Description</dt>
                    <dd class="mt-1 text-sm">{{ $transfer->description }}</dd>
                </div>
            </dl>

            <div class="border-t border-border pt-4">
                <p class="stat-label mb-3">Customer</p>
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">
                        {{ substr($transfer->user->name, 0, 1) }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ $transfer->user->name }}</p>
                        <p class="truncate text-xs text-muted-foreground">{{ $transfer->user->email }}</p>
                        <p class="mt-1 text-xs tabular-nums text-muted-foreground">
                            Wallet balance &#8358;{{ number_format($transfer->user->wallet->balance ?? 0, 2) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ========================== Bank details ======================== --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Bank details</h2>
            <p class="card-description">Where the customer was asked to send the funds.</p>
        </div>

        <div class="card-content">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <dt class="stat-label">Account name</dt>
                    <dd class="mt-1 text-sm font-medium">{{ $bankDetails['account_name'] ?? 'Your Business' }}</dd>
                </div>
                <div>
                    <dt class="stat-label">Account number</dt>
                    <dd class="mt-1 text-sm font-medium tabular-nums">{{ $bankDetails['account_number'] ?? '0123456789' }}</dd>
                </div>
                <div>
                    <dt class="stat-label">Bank name</dt>
                    <dd class="mt-1 text-sm font-medium">{{ $bankDetails['bank_name'] ?? 'Access Bank' }}</dd>
                </div>
            </dl>

            @if(isset($meta['narration']))
                <div class="mt-4 rounded-lg border border-border bg-surface p-3">
                    <p class="stat-label">Payment narration</p>
                    <p class="mt-1 text-sm">{{ $meta['narration'] }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ======================= Proof of payment ======================= --}}
    @if($proofUrl)
        <div class="card">
            <div class="card-header flex-row items-center justify-between gap-3">
                <h2 class="card-title">Proof of payment</h2>
                <a href="{{ route('admin.bank-transfers.proof.download', $transfer->id) }}" class="btn btn-outline btn-sm">
                    <x-icon name="arrow-down-tray" class="h-4 w-4" />
                    Download
                </a>
            </div>

            <div class="card-content">
                @if($isImage)
                    <div class="overflow-hidden rounded-lg border border-border">
                        <img src="{{ $proofUrl }}" alt="Proof of payment" class="h-auto max-h-64 w-full bg-surface object-contain">
                    </div>
                @elseif($isPdf)
                    <div class="flex items-center gap-3 rounded-lg border border-border bg-surface p-4">
                        <x-icon name="document-text" class="h-8 w-8 text-red-600" />
                        <div>
                            <p class="text-sm font-medium">PDF document</p>
                            <p class="text-sm text-muted-foreground">Use download to open it in a viewer.</p>
                        </div>
                    </div>
                @else
                    <div class="rounded-lg border border-border bg-surface p-4 text-sm">
                        File uploaded: {{ basename($proofUrl) }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ============================= Actions ========================== --}}
    @if($transfer->status === 'pending')
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Actions</h2>
                <p class="card-description">Approving credits the customer wallet immediately.</p>
            </div>

            <div class="card-content space-y-6">
                <form id="approveForm" action="{{ route('admin.bank-transfers.approve', $transfer->id) }}" method="POST" class="space-y-3">
                    @csrf
                    <div>
                        <label for="approveRemarks" class="label mb-2">Approval remarks (optional)</label>
                        <textarea id="approveRemarks" name="remarks" rows="2" class="textarea"
                                  placeholder="Add remarks..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-full"
                            onclick="return confirm('Approve this transfer and credit the customer wallet?')">
                        <x-icon name="check" class="h-4 w-4" />
                        Approve transfer
                    </button>
                </form>

                <form id="rejectForm" action="{{ route('admin.bank-transfers.reject', $transfer->id) }}" method="POST"
                      class="space-y-3 border-t border-border pt-6">
                    @csrf
                    <div>
                        <label for="rejectReason" class="label mb-2">Rejection reason <span class="text-destructive">*</span></label>
                        <textarea id="rejectReason" name="reason" rows="2" class="textarea" required
                                  placeholder="Provide the reason for rejection..."></textarea>
                    </div>
                    <label for="refund_pending_balance" class="flex items-center gap-2 text-sm">
                        <input type="checkbox" id="refund_pending_balance" name="refund_pending_balance" value="1" class="checkbox">
                        Refund pending balance
                    </label>
                    <button type="submit" class="btn btn-outline w-full"
                            onclick="return confirm('Reject this transfer?')">
                        <x-icon name="x-mark" class="h-4 w-4" />
                        Reject transfer
                    </button>
                </form>

                <form id="fraudForm" action="{{ route('admin.bank-transfers.mark-fraudulent', $transfer->id) }}" method="POST"
                      class="space-y-3 border-t border-border pt-6">
                    @csrf
                    <div>
                        <label for="fraudReason" class="label mb-2">Fraud reason <span class="text-destructive">*</span></label>
                        <textarea id="fraudReason" name="fraud_reason" rows="2" class="textarea" required
                                  placeholder="Explain the suspicion..."></textarea>
                    </div>
                    <div class="space-y-2">
                        <label for="suspend_user" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" id="suspend_user" name="suspend_user" value="1" class="checkbox">
                            Suspend user account
                        </label>
                        <label for="block_user" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" id="block_user" name="block_user" value="1" class="checkbox">
                            Block user permanently
                        </label>
                    </div>
                    <button type="submit" class="btn btn-destructive w-full"
                            onclick="return confirm('WARNING: mark this transfer as fraudulent and take action against the customer?')">
                        <x-icon name="exclamation-triangle" class="h-4 w-4" />
                        Mark as fraudulent
                    </button>
                </form>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Status information</h2>
            </div>

            <div class="card-content space-y-3">
                <p class="text-sm">
                    This transfer has been
                    <span class="font-medium">{{ $transfer->status }}</span>.
                </p>

                @if($transfer->status_message)
                    <p class="text-sm text-muted-foreground">{{ $transfer->status_message }}</p>
                @endif

                @if(isset($meta['approved_by']) || isset($meta['rejected_by']) || isset($meta['fraud_marked_by']))
                    <div class="space-y-2 border-t border-border pt-3">
                        <p class="stat-label">Action history</p>

                        @if(isset($meta['approved_by']))
                            <p class="flex items-center gap-2 text-sm text-green-700">
                                <x-icon name="check-circle" variant="solid" class="h-4 w-4" />
                                Approved by administrator #{{ $meta['approved_by'] }}
                            </p>
                        @endif

                        @if(isset($meta['rejected_by']))
                            <p class="flex items-center gap-2 text-sm text-red-700">
                                <x-icon name="x-mark" variant="solid" class="h-4 w-4" />
                                Rejected by administrator #{{ $meta['rejected_by'] }}
                            </p>
                        @endif

                        @if(isset($meta['fraud_marked_by']))
                            <p class="flex items-center gap-2 text-sm text-red-700">
                                <x-icon name="exclamation-triangle" variant="solid" class="h-4 w-4" />
                                Marked fraudulent by administrator #{{ $meta['fraud_marked_by'] }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
