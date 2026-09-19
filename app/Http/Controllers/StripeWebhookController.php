<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\MeetingQuotaTransaction;
use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Webhook;
use Exception;

/**
 * Stripe 決済完了通知 Webhook コントローラー
 * 【T-A-04 要件適合：二重配信防止（冪等性）・不正署名検証・署名欠落防御ガードインジェクション仕様】
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

        // 【ガード①：署名ヘッダーの完全欠落検閲早期リターン】
        // 署名ヘッダー（Stripe-Signature）自体が空っぽ、あるいは存在しない悪意あるパケットが
        // インターネット経由で届いた瞬間、Guzzleの奥深くへ突入させる前に 400 Bad Request で弾く
        $sigHeader = $request->header('Stripe-Signature');
        if (empty($sigHeader)) {
            Log::warning('Stripe Webhook 署名ヘッダー (Stripe-Signature) が完全に欠落したパケットを検知・遮断しました。');
            return response()->json(['error' => 'Header Missing'], 400);
        }

        $event = null;

        try {
            // 【ガード②：改ざん防止・不正ハッシュパケットの物理認証】
            // ペイロード、ヘッダー、秘密鍵を突き合わせ、1文字でも改ざんがあれば 400 エラーを返す
            $event = Webhook::constructEvent($payload, $sigHeader, $this->endpointSecret);
        } catch (Exception $e) {
            Log::error('Stripe Webhook 署名検証に失敗しました。不正な改ざんパケットの可能性があります。', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // 2. 決済完了イベント（checkout.session.completed）の検知・抽出
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            $userId = $session->metadata->user_id ?? null;
            $packId = $session->metadata->meeting_pack_id ?? null;

            if (!$userId || !$packId) {
                Log::error('Stripe Webhook 必要なメタデータが欠落しています。', ['session_id' => $session->id]);
                return response()->json(['error' => 'Missing metadata'], 400);
            }

            // 【ガード③：冪等性（Idempotency）保証エンジンによる二重加算の防御】
            // Stripe側のネットワークリトライ等で全く同じセッションIDの決済通知が複数回重複して届いたとしても、
            // 受講生の面談残数が 2 重に増殖して会計が崩れるのを防ぐため、すでに控えがある場合は
            // 重複ログを刻んで、処理を安全にスキップして「200 OK (duplicated_ignored)」を返却する
            $existingPayment = Payment::where('stripe_checkout_session_id', $session->id)->first();
            if ($existingPayment) {
                Log::info('Stripe Webhook 重複した決済通知を検知しました。二重計上を防御し、安全に 200 OK を返します。', ['session_id' => $session->id]);
                return response()->json(['status' => 'duplicated_ignored'], 200);
            }

            // マスタデータの存在確認
            $pack = MeetingPack::find($packId);
            $user = User::find($userId);

            if (!$pack || !$user) {
                Log::error('Stripe Webhook ユーザーまたは面談パックがデータベースに見つかりません。', ['user_id' => $userId, 'pack_id' => $packId]);
                return response()->json(['error' => 'Entity not found'], 404);
            }

            // 3. データベースの原子性（Atomicity）を保証するトランザクション処理の開始
            DB::beginTransaction();
            try {
                // ① 決済履歴の永続化
                $payment = Payment::create([
                    'user_id'                     => $user->id,
                    'meeting_pack_id'             => $pack->id,
                    'amount'                      => $pack->price,
                    'quantity'                    => $pack->meeting_count,
                    'status'                      => PaymentStatus::Completed,
                    'stripe_checkout_session_id' => $session->id,
                    'stripe_payment_intent_id'   => $session->payment_intent ?? null,
                ]);

                // ② 元帳方式（銀行口座方式）による面談残数加算の安全な執行
                MeetingQuotaTransaction::create([
                    'user_id'            => $user->id,
                    'type'               => MeetingQuotaTransactionType::Purchased,
                    'amount'             => (int) $pack->meeting_count,
                    'related_payment_id' => $payment->id,
                    'note'               => "追加面談パック「{$pack->name}」の購入による付与",
                    'occurred_at'        => now(),
                ]);

                DB::commit();
                Log::info('Stripe Webhook 決済完了および面談回数の加算処理が成功しました。', ['user_id' => $user->id, 'payment_id' => $payment->id]);
            } catch (Exception $e) {
                DB::rollBack();
                Log::error('Stripe Webhook データベース永続化中に致命的なエラーが発生しました。', ['error' => $e->getMessage()]);
                return response()->json(['error' => 'Database error'], 500);
            }
        }

        return response()->json(['status' => 'success'], 200);
    }
}
