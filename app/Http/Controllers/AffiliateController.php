<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Services\AffiliateService;
use Illuminate\Http\Request;

class AffiliateController extends Controller
{
    public function __construct(private AffiliateService $affiliates)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        // Issue a code on first visit so the page is never empty.
        $this->affiliates->ensureCode($user);

        $referrals = Referral::with('referred:id,name,created_at')
            ->where('referrer_id', $user->id)
            ->latest('paid_at')
            ->limit(50)
            ->get();

        return view('affiliate.index', [
            'user' => $user->fresh(),
            'shareUrl' => $this->affiliates->shareUrl($user),
            'rewardAmount' => AffiliateService::REWARD_AMOUNT,
            'qualifyingAmount' => AffiliateService::QUALIFYING_AMOUNT,
            'totalEarned' => $this->affiliates->totalEarned($user),
            'qualifiedCount' => $this->affiliates->qualifiedCount($user),
            'invitedCount' => $this->affiliates->invitedCount($user),
            'referrals' => $referrals,
        ]);
    }
}
