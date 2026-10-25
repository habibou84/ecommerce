<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Services\Partners\PartnerReferrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console : parrainage par les entreprises de livraison (part de l'abonnement, durée) et versements.
 */
class ConsoleReferralController extends Controller
{
    public function __construct(private readonly PartnerReferrals $referrals) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => ['settings' => $this->referrals->settings(), 'partners' => $this->referrals->partners()]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'between:0,0.5'],
            'months' => ['required', 'integer', 'between:1,60'],
        ]);
        $this->referrals->updateSettings(['rate' => round((float) $data['rate'], 4), 'months' => (int) $data['months']]);
        $this->log($request, 'referral_settings_updated', $data);

        return $this->index();
    }

    public function pay(Request $request, string $partner): JsonResponse
    {
        $data = $request->validate([
            'method' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $payout = $this->referrals->pay($partner, $request->user(), $data);
        $this->log($request, 'referral_paid', ['partner' => $partner, 'amount' => $payout->amount, 'payout_id' => $payout->id]);

        return $this->index();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function log(Request $request, string $action, array $data): void
    {
        AdminAction::create(['admin_id' => $request->user()->id, 'store_id' => null, 'action' => $action, 'data' => $data, 'ip' => $request->ip()]);
    }
}
