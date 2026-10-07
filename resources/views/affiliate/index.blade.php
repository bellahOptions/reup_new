@extends('layouts.app')

@section('title', 'Refer and earn')

@section('content')
<main class="container-page py-8 md:py-12">

    <header class="mb-8">
        <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Refer and earn &#8358;{{ number_format($rewardAmount, 0) }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
            Share your link. When someone you invited funds their wallet with
            &#8358;{{ number_format($qualifyingAmount, 0) }} or more, we credit
            &#8358;{{ number_format($rewardAmount, 0) }} to your wallet straight away &mdash;
            no waiting, no approval step.
        </p>
    </header>

    @if(session('success'))
        <div class="mb-6 flex items-start gap-2.5 rounded-lg border border-brand-200 bg-accent px-4 py-3">
            <x-icon name="check-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
            <p class="text-sm text-accent-foreground">{{ session('success') }}</p>
        </div>
    @endif

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="card">
            <div class="card-content">
                <p class="stat-label">Total earned</p>
                <p class="stat-value mt-1 tabular-nums text-brand-700">
                    &#8358;{{ number_format($totalEarned, 2) }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">Credited directly to your wallet</p>
            </div>
        </div>
        <div class="card">
            <div class="card-content">
                <p class="stat-label">Qualified referrals</p>
                <p class="stat-value mt-1 tabular-nums">{{ $qualifiedCount }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Funded &#8358;{{ number_format($qualifyingAmount, 0) }}+
                </p>
            </div>
        </div>
        <div class="card">
            <div class="card-content">
                <p class="stat-label">Signed up with your link</p>
                <p class="stat-value mt-1 tabular-nums">{{ $invitedCount }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ $invitedCount - $qualifiedCount > 0 ? ($invitedCount - $qualifiedCount) . ' yet to fund' : 'All funded' }}
                </p>
            </div>
        </div>
    </div>

    {{-- Share --}}
    <section class="card mb-6" x-data="{
        copied: false,
        async copy() {
            try {
                await navigator.clipboard.writeText(@js($shareUrl));
                this.copied = true;
                setTimeout(() => this.copied = false, 2000);
            } catch (e) {}
        }
    }">
        <div class="card-header">
            <h2 class="card-title">Your referral link</h2>
            <p class="card-description">Anyone who signs up through this link is credited to you.</p>
        </div>
        <div class="card-content space-y-4">
            <div class="flex flex-col gap-2 sm:flex-row">
                <input type="text"
                       readonly
                       value="{{ $shareUrl }}"
                       aria-label="Your referral link"
                       onclick="this.select()"
                       class="input font-mono text-xs sm:text-sm">
                <button type="button" @click="copy()" class="btn btn-primary shrink-0">
                    <x-icon name="document-duplicate" class="h-4 w-4" />
                    <span x-show="!copied">Copy link</span>
                    <span x-show="copied" x-cloak>Copied</span>
                </button>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Your code</p>
                <p class="mt-1 font-mono text-lg font-bold tracking-wider">{{ $user->referral_code }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="https://wa.me/?text={{ urlencode('Join me on ReUp — buy airtime, data and pay bills. Sign up with my link: ' . $shareUrl) }}"
                   target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm">
                    <x-icon name="whatsapp" variant="solid" class="h-4 w-4 text-[#128C7E]" />
                    Share on WhatsApp
                </a>
                <a href="sms:?body={{ urlencode('Join me on ReUp: ' . $shareUrl) }}" class="btn btn-outline btn-sm">
                    <x-icon name="device-phone-mobile" class="h-4 w-4" />
                    Share by SMS
                </a>
                <a href="mailto:?subject={{ urlencode('Join me on ReUp') }}&body={{ urlencode('Sign up with my link: ' . $shareUrl) }}"
                   class="btn btn-outline btn-sm">
                    <x-icon name="envelope" class="h-4 w-4" />
                    Share by email
                </a>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section class="card mb-6">
        <div class="card-header">
            <h2 class="card-title">How it works</h2>
        </div>
        <div class="card-content">
            <ol class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach([
                    ['Share your link', 'Send it to anyone who pays bills online.'],
                    ['They sign up and fund', 'Add at least &#8358;' . number_format($qualifyingAmount, 0) . ' to their wallet — card or bank transfer.'],
                    ['You get &#8358;' . number_format($rewardAmount, 0), 'Credited to your wallet instantly, and shown in your transaction history.'],
                ] as $index => $step)
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                            {{ $index + 1 }}
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold">{!! $step[0] !!}</span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">{!! $step[1] !!}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- History --}}
    <section class="card overflow-hidden">
        <div class="card-header">
            <h2 class="card-title">Reward history</h2>
            <p class="card-description">
                {{ $qualifiedCount }} reward{{ $qualifiedCount === 1 ? '' : 's' }} paid
            </p>
        </div>

        @forelse($referrals as $referral)
            @if($loop->first)
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Referred user</th>
                                <th scope="col">Their funding</th>
                                <th scope="col" class="text-right">You earned</th>
                            </tr>
                        </thead>
                        <tbody>
            @endif

                        <tr>
                            <td class="whitespace-nowrap text-sm">
                                {{ $referral->paid_at?->format('M j, Y') }}
                                <span class="block text-xs text-muted-foreground">{{ $referral->paid_at?->format('g:i A') }}</span>
                            </td>
                            <td class="text-sm font-medium">{{ $referral->referred?->name ?? 'Deleted user' }}</td>
                            <td class="text-sm tabular-nums text-muted-foreground">
                                &#8358;{{ number_format((float) $referral->qualifying_amount, 2) }}
                            </td>
                            <td class="whitespace-nowrap text-right text-sm font-semibold tabular-nums text-brand-700">
                                +&#8358;{{ number_format((float) $referral->reward_amount, 2) }}
                            </td>
                        </tr>

            @if($loop->last)
                        </tbody>
                    </table>
                </div>
            @endif
        @empty
            <div class="card-content">
                <div class="empty-state">
                    <x-icon name="users" class="h-6 w-6" />
                    <p class="mt-3 text-sm font-medium">No rewards yet</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Share your link to get started. Your first &#8358;{{ number_format($rewardAmount, 0) }}
                        lands as soon as someone you invited funds their wallet.
                    </p>
                </div>
            </div>
        @endforelse
    </section>

    {{-- The terms, stated plainly. Referring yourself is the obvious abuse and
         saying so up front is fairer than silently refusing the reward. --}}
    <div class="mt-6 flex items-start gap-2.5 rounded-lg border border-border bg-surface px-4 py-3">
        <x-icon name="information-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
        <p class="text-xs text-muted-foreground">
            One reward per person you refer. Self-referrals, duplicate accounts and
            fraudulent funding do not qualify, and rewards may be reversed if the funding
            behind them is reversed. The qualifying amount is measured on lifetime wallet
            funding, so several smaller top-ups count.
        </p>
    </div>
</main>
@endsection
