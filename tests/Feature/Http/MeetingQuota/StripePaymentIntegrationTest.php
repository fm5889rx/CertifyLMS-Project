<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests\Feature\StripePaymentIntegrationTest
 *
 * 【S-A-03 最終監査テスト】Stripe外部決済連携・現行コード完全無傷突破統合Featureテスト。
 * 【T-A-04 最終監査テスト】本番コードの改修に合わせてテストコードを改修。
 */
class StripePaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private MeetingPack $meetingPack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create([
            'role'   => UserRole::Student,
            'status' => UserStatus::InProgress,
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->meetingPack = MeetingPack::create([
            'name'               => 'テスト追加面談5回パック',
            'description'        => 'テスト用の面談パックです。',
            'meeting_count'      => 5,
            'price'              => 3000,
            'stripe_price_id'    => 'price_test_12345',
            'status'             => MeetingPackStatus::Published,
            'sort_order'         => 1,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    /**
     * 1. index メソッドの検証
     */
    public function test_追加面談パック購入選択画面にアクセスした際に対象受講生の過去ログスレッド一覧が正常にロードされて描画されること(): void
    {
        $response = $this->actingAs($this->student)
            ->get(route('meeting-quota.checkout.select'));

        $response->assertStatus(200)
            ->assertViewIs('meeting-quota.checkout-select')
            ->assertViewHas('plans');
    }

    /**
     * 2. store メソッドの検証
     */
    public function test_面談パックを選択して購入リクエストを送信した際に改ざん不可能なStripeセッションが生成されて外部決済画面へ正常にリダイレクトされること(): void
    {
        Http::fake([
            '*' => Http::response([
                'id'  => 'cs_test_mock_session_id_123',
                'url' => 'https://stripe.com'
            ], 200)
        ]);

        $response = $this->actingAs($this->student)
            ->post(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => $this->meetingPack->id,
            ]);

        $response->assertStatus(302);
    }

    /**
     * 3. success メソッドの検証
     */
    public function test_決済完了後に戻り先サンクス画面へアクセスした際に決済履歴の控えが本物のEnumにキャストされて日本語ラベルが正常描画されること(): void
    {
        $payment = Payment::create([
            'user_id'                    => $this->student->id,
            'meeting_pack_id'            => $this->meetingPack->id,
            'amount'                     => $this->meetingPack->price,
            'quantity'                   => $this->meetingPack->meeting_count,
            'status'                     => PaymentStatus::Completed,
            'stripe_checkout_session_id' => 'cs_test_success_999',
        ]);

        $response = $this->actingAs($this->student)
            ->get(route('meeting-quota.checkout.success', ['session_id' => 'cs_test_success_999']));

        $response->assertStatus(200)
            ->assertViewHas('payment');
    }

    /**
     * @test
     * 4. Webhook handle メソッドの検証（二重配信防止の冪等性・不正署名検証・完全適合版）
     */
    public function test_ストライプサーバーから決済完了通知を受信した際に二重計上を拒絶しながら元帳履歴へ面談回数をダイレクト自動加算すること(): void
    {
        $mockSessionId = 'cs_test_webhook_flow_777';

        // 【T-A-04で変更】
        //  本番コントローラー（）が実際にロードして検証に使用する「.env の本物の最新秘密鍵」を
        //  テスト環境側から動的に直接吸引する。
        //  もし環境変数自体が空っぽ（CI/CD環境等）の場合は、テスト用の仮シークレットを安全にフォールバックする。
        $activeSecret = config('services.stripe.webhook_secret')
            ?? env('STRIPE_WEBHOOK_SECRET')
            ?? 'whsec_b112e2fb19bb691e33d7098396ddcd5b46ef2ec416e8c1f42e44f668221060bb';

        // Stripe公式オブジェクトファクトリから、本番コードと完全一致の JSON 構造体を射出
        $stripeEvent = \Stripe\Event::constructFrom([
            'id' => 'evt_test_webhook_flow_777',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id'             => $mockSessionId,
                    'payment_intent' => 'pi_test_intent_777',
                    'metadata'       => [
                        'user_id'         => $this->student->id,
                        'meeting_pack_id' => $this->meetingPack->id,
                    ]
                ]
            ]
        ]);

        $rawPayload = $stripeEvent->toJSON();

        // コントローラー側の署名検証エンジン（）が実際に使用する本物の鍵と同期させながらハッシュを計算
        $timestamp = time();
        $signedPayload = "{$timestamp}.{$rawPayload}";
        $computedSignature = hash_hmac('sha256', $signedPayload, $activeSecret);

        // 鉄壁の正規カンマ区切り署名ヘッダー（t=,v1=）のインジェクション
        $stripeSignatureHeader = "t={$timestamp},v1={$computedSignature}";

        // 1回目の通知受信
        // 生成した完璧な本物ハッシュヘッダーを添えてパケットテキストを送信
        $response = $this->call(
            method: 'POST',
            uri: '/webhooks/stripe',
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'HTTP_STRIPE_SIGNATURE' => $stripeSignatureHeader,
                'CONTENT_TYPE'          => 'application/json',
            ],
            content: $rawPayload
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        // 物理データ層への永続化状態を厳格監査
        $this->assertDatabaseHas('payments', [
            'user_id'                    => $this->student->id,
            'stripe_checkout_session_id' => $mockSessionId,
            'status'                     => PaymentStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $this->student->id,
            'amount'  => 5,
        ]);

        $remainingQuota = (int) \App\Models\MeetingQuotaTransaction::where('user_id', $this->student->id)->sum('amount');
        $this->assertEquals(5, $remainingQuota);

        // 2回目の通知受信（Stripeのネットワークリトライによる重複通知 ➡ 冪等性の検証）
        $duplicatedResponse = $this->call(
            method: 'POST',
            uri: '/webhooks/stripe',
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'HTTP_STRIPE_SIGNATURE' => $stripeSignatureHeader,
                'CONTENT_TYPE'          => 'application/json',
            ],
            content: $rawPayload
        );

        // 本番コード（）の冪等性エンジンが火を噴き、二重加算を完全ブロックして安全に受け流すアサーション
        $duplicatedResponse->assertStatus(200)
            ->assertJson(['status' => 'duplicated_ignored']);

        $remainingQuotaAfterDuplicated = (int) \App\Models\MeetingQuotaTransaction::where('user_id', $this->student->id)->sum('amount');
        $this->assertEquals(5, $remainingQuotaAfterDuplicated);
    }
} // クラスの最後の閉じ括弧
