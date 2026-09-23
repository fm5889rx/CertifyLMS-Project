<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class MeetingQuotaCheckoutController extends Controller
{
    public function __construct()
    {
        // .env に隔離した Stripe の秘密鍵（Secret Key）を SDK へ注入
        Stripe::setApiKey(config('services.stripe.secret', env('STRIPE_SECRET_KEY', '')));
    }

    /**
     * 追加面談パックの購入選択画面を表示
     */
    public function index(): View
    {
        // 公開中（status => published）の面談パックのみを、ソート順に従って吸引
        $plans = MeetingPack::where('status', MeetingPackStatus::Published)
            ->orderBy('sort_order', 'asc')
            ->orderByDesc('created_at')
            ->get();

        return view('meeting-quota.checkout-select', compact('plans'));
    }

    /**
     * Stripe Checkout セッションを生成し、外部決済画面へリダイレクト発射
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'meeting_pack_id' => 'required|string',
        ]);

        // 物理監査：フロントからの価格は使用せず、マスタから直接価格と回数をロード
        $pack = MeetingPack::where('status', MeetingPackStatus::Published)
            ->findOrFail($request->input('meeting_pack_id'));

        $user = auth()->user();

        try {
            // Stripe Checkout セッションの組み立て
            $session = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'jpy', // 通貨は円（JPY）のみ
                        'product_data' => [
                            'name' => $pack->name,
                            'description' => $pack->description ?? "追加面談回数: {$pack->meeting_count}回分",
                        ],
                        'unit_amount' => $pack->price, // バックエンドから引いた安全な本物の金額
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment', // 都度購入（サブスクリプションなし）

                // 完了画面とキャンセル画面の戻り先パスを綺麗に定義
                'success_url' => route('meeting-quota.checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('meeting-quota.checkout.select'),

                // Stripe 側へ、誰が（user_id）何を（meeting_pack_id）買ったのかをバインド
                'metadata' => [
                    'user_id' => $user->id,
                    'meeting_pack_id' => $pack->id,
                ],
            ]);

            // 外部決済画面（Stripe 委譲）へリダイレクト発射
            return redirect($session->url);

        } catch (\Exception $e) {
            Log::error('Stripe Checkoutセッションの生成に失敗しました。', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['error' => '決済画面への遷移に失敗しました。時間をおいて再度お試しください。']);
        }
    }

    /**
     * 決済完了後のサンクスページ表示（戻り先画面）
     */
    public function success(Request $request): View
    {
        $sessionId = $request->query('session_id');
        $payment = null;

        if ($sessionId) {
            // Webhook 側が保存してくれている本物の決済履歴をピッキング
            $payment = Payment::where('stripe_checkout_session_id', $sessionId)
                ->with('meetingPack')
                ->first();
        }

        return view('meeting-quota.success', compact('payment'));
    }
}
