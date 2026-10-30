<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 開発用 面談パック購入決済履歴シーダー（PaymentStatus Enum完全適合版）
 */
class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        // 1. ローカルDBに実在する受講生（Student）を一括ロード
        $students = User::where('role', UserRole::Student->value ?? UserRole::Student)->get();

        // 2. ⭕ 【確実な絞り込み】ステータスが「published（公開中）」のマスタのみを完全に型ロックして取得
        $packs = MeetingPack::where('status', MeetingPackStatus::Published->value ?? 'published')->get();

        if ($students->isEmpty() || $packs->isEmpty()) {
            $this->command?->warn('PaymentSeeder: 受講生または公開中の面談パックが存在しないため、スキップしました。先に UserSeeder と MeetingPackSeeder を実行してください。');
            return;
        }

        // 3. 各受講生に対して、ランダムに複数の面談パックの購入履歴（Payment）を型安全に注入
        foreach ($students as $student) {
            // 1人あたり 1〜3 件の購入履歴をランダムに生成
            $purchaseCount = rand(1, 3);
            
            for ($i = 0; $i < $purchaseCount; $i++) {
                $pack = $packs->random();

                // 🎲 確率の割り振り（1:保留中、2:決済失敗、その他:決済完了）
                $dice = rand(1, 10); 

                $factory = Payment::factory();
                if ($dice === 1) {
                    $factory = $factory->state([
                        'status' => PaymentStatus::Pending->value, // ⭕ 'pending'（保留中）
                    ]);
                } elseif ($dice === 2) {
                    $factory = $factory->state([
                        'status' => PaymentStatus::Failed->value, // ⭕ 'failed'（決済失敗）
                    ]);
                } else {
                    $factory = $factory->state([
                        'status' => PaymentStatus::Completed->value, // ⭕ 'completed'（決済完了）
                    ]);
                }

                $factory->state([
                    'meeting_pack_id' => $pack->id,
                    'user_id' => $student->id,
                    'amount' => $pack->price,
                    'quantity' => $pack->meeting_count,
                ])->create();
            }
        }
    }
}
