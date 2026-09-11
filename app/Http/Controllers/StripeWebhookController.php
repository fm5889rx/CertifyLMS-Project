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

class StripeWebhookController extends Controller
{
    // Webhookシークレットをクラスプロパティとして厳格に隔離保持します。
    private string $endpointSecret;

    public function __construct()
    {
        // 💡 .env の生の値を直接見に行かず、Laravel の設定レイヤー（config）から取得
        Stripe::setApiKey(config('services.stripe.secret', env('STRIPE_SECRET_KEY', '')));

        $this->endpointSecret = (string) config('services.stripe.webhook_secret', env('STRIPE_WEBHOOK_SECRET', ''));
    }

    /**
     * Stripe 決済サーバーからの Webhook イベントを受信・正当性検証
     */
    public function handle(Request $request): JsonResponse
    {
        // API Key の取得
        Stripe::setApiKey(config('services.stripe.secret', env('STRIPE_SECRET_KEY', '')));
        $endpointSecret = config('services.stripe.webhook_secret', env('STRIPE_WEBHOOK_SECRET', ''));

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $event = null;

        try {
            // 1. 決済通知の改ざん防止
            // 送信されてきた生のペイロードと、署名ヘッダー、そしてWebHookシークレットを突き合わせて
            // パケットが改ざんされていないかを物理層で認証する
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (Exception $e) {
            Log::error('Stripe Webhook 署名検証に失敗しました。不正パケットの可能性があります。', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // 2. 決済完了イベント（checkout.session.completed）の検知・抽出
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            // Metadata に仕込んでおいたバトン（user_id / meeting_pack_id）を安全にパース
            $userId = $session->metadata->user_id ?? null;
            $packId = $session->metadata->meeting_pack_id ?? null;

            if (!$userId || !$packId) {
                Log::error('Stripe Webhook 必要なメタデータが欠落しています。', ['session_id' => $session->id]);
                return response()->json(['error' => 'Missing metadata'], 400);
            }

            // 3. 冪等性ガードによる二重加算の防御
            // Stripeの再送等で同じセッションIDの通知が二重で届いても、残数の会計が絶対に崩れないよう、
            // データベースにすでに同じ stripe_checkout_session_id の控え（Payment）があるかチェック
            $existingPayment = Payment::where('stripe_checkout_session_id', $session->id)->first();
            if ($existingPayment) {
                Log::info('Stripe Webhook 重複した決済通知を検知しました。処理をスキップして安全に 200 OK を返します。', ['session_id' => $session->id]);
                return response()->json(['status' => 'duplicated_ignored'], 200);
            }

            // マスタデータの安全な最終確認
            $pack = MeetingPack::find($packId);
            $user = User::find($userId);

            if (!$pack || !$user) {
                Log::error('Stripe Webhook ユーザーまたは面談パックがデータベースに見つかりません。', ['user_id' => $userId, 'pack_id' => $packId]);
                return response()->json(['error' => 'Entity not found'], 404);
            }

            // 4. データベースの原子性を保証するトランザクション処理の開始
            DB::beginTransaction();
            try {
                // ① 決済の歴史改ざん防止
                //    購入確定時点のマスタの生の値（価格、付与回数）をここに独立して永続化
                $payment = Payment::create([
                    'user_id'                     => $user->id,
                    'meeting_pack_id'             => $pack->id,
                    'amount'                      => $pack->price, // 購入時点の価格
                    'quantity'                    => $pack->meeting_count, // 購入時点の付与回数
                    'status'                      => PaymentStatus::Completed,
                    'stripe_checkout_session_id' => $session->id,
                    'stripe_payment_intent_id'   => $session->payment_intent ?? null,
                ]);

                // ② 銀行口座方式（元帳方式）による残数加算の執行
                // カラムのインクリメントではなく、取引履歴テーブルへプラス（+）のレコードを1行追加
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

        // 想定外の通知イベントが届いても処理を破綻させず、安全に 200 OK で受け流す
        return response()->json(['status' => 'success'], 200);
    }
}
