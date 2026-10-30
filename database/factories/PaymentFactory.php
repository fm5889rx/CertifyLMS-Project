<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * 【横断品質・データ整合性完全ガード版】決済履歴のデフォルト定義
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'user_id' => User::factory()->student(),
            
            // ⭕ 【最終兵器】どこからこのファクトリが呼ばれても、下書き（draft）のパックを絶対に自動生成させないロジック
            'meeting_pack_id' => function () {
                // すでにマスタシーダー（MeetingPackSeeder）によって作られた「本物の公開中（published）のパック」をDBから1件ランダムに取得
                $publishedPack = MeetingPack::where('status', MeetingPackStatus::Published->value ?? 'published')->inRandomOrder()->first();
                
                // もし他シーダーの実行順の影響でDBにまだ無ければ、その場で1件だけ「公開中」マスタを安全に自動生成してそのIDを返す
                return $publishedPack ? $publishedPack->id : MeetingPack::factory()->published()->create()->id;
            },
            
            'amount' => function (array $attributes) {
                return MeetingPack::find($attributes['meeting_pack_id'])->price ?? 3000;
            },
            'quantity' => function (array $attributes) {
                return MeetingPack::find($attributes['meeting_pack_id'])->meeting_count ?? 1;
            },
            'status' => PaymentStatus::Completed->value,
            'stripe_checkout_session_id' => 'cs_test_' . Str::random(20),
            'stripe_payment_intent_id' => 'pi_test_' . Str::random(20),
        ];
    }

    /**
     * 状態遷移：決済成功
     */
    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Completed->value,
        ]);
    }

    /**
     * 状態遷移：決済保留
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Pending->value,
            'stripe_payment_intent_id' => null,
        ]);
    }

    /**
     * 状態遷移：決済失敗
     */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Failed->value,
        ]);
    }
}
