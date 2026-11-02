<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stripe\PrepareCheckoutAction;
use App\Enums\MeetingPackStatus;
use App\Http\Requests\MeetingQuota\StoreCheckoutRequest;
use App\Models\MeetingPack;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class MeetingQuotaCheckoutController extends Controller
{
    public function __construct(
        private readonly PrepareCheckoutAction $prepareCheckoutAction
    ) {}

    /**
     * 追加面談パックの購入選択画面を表示
     */
    public function index(): View
    {
        $plans = MeetingPack::where('status', MeetingPackStatus::Published)
            ->orderBy('sort_order', 'asc')
            ->orderByDesc('created_at')
            ->get();

        return view('meeting-quota.checkout-select', compact('plans'));
    }

    /**
     * Stripe Checkout セッションを生成し、外部決済画面へリダイレクト
     */
    public function store(StoreCheckoutRequest $request): RedirectResponse
    {
        $pack = MeetingPack::where('status', MeetingPackStatus::Published)
            ->findOrFail($request->validated('meeting_pack_id'));

        try {
            $checkoutUrl = $this->prepareCheckoutAction->execute(auth()->user(), $pack);

            return redirect($checkoutUrl);

        } catch (\Exception $e) {
            Log::error('Stripe Checkoutセッションの生成に失敗しました。', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['error' => '決済画面への遷移に失敗しました。時間をおいて再度お試しください。']);
        }
    }

    /**
     * 決済完了後のサンクスページ表示
     */
    public function success(Request $request): View
    {
        $sessionId = $request->query('session_id');
        $payment = null;

        if ($sessionId) {
            $payment = Payment::where('stripe_checkout_session_id', $sessionId)
                ->with('meetingPack')
                ->first();
        }

        return view('meeting-quota.success', compact('payment'));
    }
}
