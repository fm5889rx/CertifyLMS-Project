<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * Stripe 決済完了通知 Webhook コントローラー
 */
class StripeWebhookController extends Controller
{
    private string $endpointSecret;

    public function __construct()
    {
        Stripe::setApiKey((string) config('services.stripe.secret', env('STRIPE_SECRET_KEY', '')));
        $this->endpointSecret = (string) config('services.stripe.webhook_secret', env('STRIPE_WEBHOOK_SECRET', ''));
    }

    /**
     * Stripe 決済サーバーからの Webhook イベントを受信・正当性検証
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        if (empty($sigHeader)) {
            Log::warning('Stripe Webhook 署名ヘッダーが欠落しています。');
            return response()->json(['error' => 'Header Missing'], 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $this->endpointSecret);
        } catch (Exception $e) {
            Log::error('Stripe Webhook 署名検証に失敗しました。', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // ① 決済完了イベント（checkout.session.completed）の検知
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            $userId = $session->metadata->user_id ?? null;
            $packId = $session->metadata->meeting_pack_id ?? null;

            if (! $userId || ! $packId) {
                return response()->json(['error' => 'Missing metadata'], 400);
            }

            // 既存レコード（保留中データ）の有無を確認
            $payment = Payment::where('stripe_checkout_session_id', $session->id)->first();
            if ($payment && $payment->status === PaymentStatus::Completed) {
                return response()->json(['status' => 'duplicated_ignored'], 200);
            }

            $pack = MeetingPack::find($packId);
            $user = User::find($userId);

            if (! $pack || ! $user) {
                return response()->json(['error' => 'Entity not found'], 404);
            }

            DB::beginTransaction();
            try {
                // 先行の保留レコードがないテストケース（直撃）が来た場合でも、
                // 回数（$pack->meeting_count）を完璧に引き連れて新規作成、あれば更新遷移させる
                if (!$payment) {
                    $payment = Payment::create([
                        'user_id' => $user->id,
                        'meeting_pack_id' => $pack->id,
                        'amount' => $pack->price,
                        'quantity' => $pack->meeting_count,
                        'status' => PaymentStatus::Completed,
                        'stripe_checkout_session_id' => $session->id,
                        'stripe_payment_intent_id' => $session->payment_intent ?? null,
                    ]);
                } else {
                    $payment->update([
                        'status' => PaymentStatus::Completed,
                        'stripe_payment_intent_id' => $session->payment_intent ?? null,
                    ]);
                }

                // 同一トランザクション内で、マスタの正規の回数を確実に残数元帳へ自動加算！
                MeetingQuotaTransaction::create([
                    'user_id' => $user->id,
                    'type' => MeetingQuotaTransactionType::Purchased,
                    'amount' => (int) $pack->meeting_count,
                    'related_payment_id' => $payment->id,
                    'note' => "追加面談パック「{$pack->name}」の購入による付与",
                    'occurred_at' => now(),
                ]);

                DB::commit();
                Log::info('Stripe Webhook 決済完了処理成功。');
            } catch (Exception $e) {
                DB::rollBack();
                Log::error('Stripe Webhook データベース永続化エラー:', ['error' => $e->getMessage()]);
                return response()->json(['error' => 'Database error'], 500);
            }
        }

        // ② 決済失敗・期限切れイベント時の保留レコード失敗状態遷移
        if ($event->type === 'checkout.session.expired' || $event->type === 'charge.failed') {
            $session = $event->data->object;
            $payment = Payment::where('stripe_checkout_session_id', $session->id)->first();

            if ($payment) {
                DB::beginTransaction();
                try {
                    $payment->update([
                        'status' => PaymentStatus::Failed,
                    ]);
                    DB::commit();
                    Log::info('Stripe Webhook 決済失敗に連動した状態遷移完了。');
                } catch (Exception $e) {
                    DB::rollBack();
                    return response()->json(['error' => 'Database error'], 500);
                }
            }
        }

        return response()->json(['status' => 'success'], 200);
    }
}
