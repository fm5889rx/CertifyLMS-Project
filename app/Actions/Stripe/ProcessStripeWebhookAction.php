<?php

declare(strict_types=1);

namespace App\Actions\Stripe;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Exception;

final class ProcessStripeWebhookAction
{
    /**
     * Webhookイベントに応じた状態遷移およびクォータ加算を執行する
     */
    public function execute(Event $event): array
    {
        // ① 決済成功イベント時の状態遷移（completed）
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            $payment = Payment::where('stripe_checkout_session_id', $session->id)->first();

            // 冪等性（二重処理防止）のガード
            if ($payment && $payment->status === PaymentStatus::Completed) {
                return ['status' => 'duplicated_ignored', 'code' => 200];
            }

            // テスト環境等で先行レコードがなかった場合へのセーフティフォールバック
            if (!$payment) {
                $userId = $session->metadata->user_id ?? null;
                $packId = $session->metadata->meeting_pack_id ?? null;
                if (!$userId || !$packId) {
                    return ['status' => 'Missing metadata', 'code' => 400];
                }

                $pack = MeetingPack::find($packId);
                $user = User::find($userId);
                if (!$pack || !$user) {
                    return ['status' => 'Entity not found', 'code' => 404];
                }
                
                DB::beginTransaction();
                try {
                    $payment = Payment::create([
                        'user_id' => $user->id,
                        'meeting_pack_id' => $pack->id,
                        'amount' => $pack->price, // 💡【修正】Noticeの原因となっていた $session->amount_total をマスタの安全な金額へ置換
                        'quantity' => $pack->meeting_count,
                        'status' => PaymentStatus::Completed,
                        'stripe_checkout_session_id' => $session->id,
                        'stripe_payment_intent_id' => $session->payment_intent ?? null,
                    ]);
                    DB::commit();
                    return ['status' => 'success', 'code' => 200];
                } catch (Exception $e) {
                    DB::rollBack();
                    return ['status' => 'Database error', 'code' => 500];
                }
            }

            // 同一トランザクション内でのステータス状態遷移 ＆ クォータ会計加算
            DB::beginTransaction();
            try {
                $payment->update([
                    'status' => PaymentStatus::Completed,
                    'stripe_payment_intent_id' => $session->payment_intent ?? null,
                ]);

                MeetingQuotaTransaction::create([
                    'user_id' => $payment->user_id,
                    'type' => MeetingQuotaTransactionType::Purchased,
                    'amount' => (int) $payment->quantity,
                    'related_payment_id' => $payment->id,
                    'note' => "追加面談パック「{$payment->meetingPack->name}」の購入による付与",
                    'occurred_at' => now(),
                ]);

                DB::commit();
                Log::info('Stripe Webhook 決済完了に連動した状態遷移および面談回数加算が完了。', ['payment_id' => $payment->id]);
                return ['status' => 'success', 'code' => 200];

            } catch (Exception $e) {
                DB::rollBack();
                Log::error('Stripe Webhook 状態遷移中にエラーが発生しました。', ['error' => $e->getMessage()]);
                return ['status' => 'Database error', 'code' => 500];
            }
        }

        // ② 決済失敗・期限切れイベント時の状態遷移（failed）
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
                    Log::info('Stripe Webhook 決済失敗に連動した状態遷移（Failed）が完了。', ['payment_id' => $payment->id]);
                    return ['status' => 'failed_processed', 'code' => 200];
                } catch (Exception $e) {
                    DB::rollBack();
                    return ['status' => 'Database error', 'code' => 500];
                }
            }
        }

        return ['status' => 'success', 'code' => 200];
    }
}
