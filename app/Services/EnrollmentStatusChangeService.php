<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Enrollment 状態遷移の監査ログ(`EnrollmentStatusLog`)を INSERT する Service。
 *
 * 呼出側 Action がトランザクション内で recordStatusChange() を呼ぶ前提。本 Service 自体は
 * DB::transaction() を持たない(`backend-services.md` の規約準拠、ステートレス INSERT only)。
 *
 * `final` 不採用: Mockery で recordStatusChange を mock してトランザクション原子性の rollback 検証を
 * Action テストで行う可能性があるため(`UserStatusChangeService` と同じ判断軸)。
 *
 * 【T-A-06 要件適合：状態遷移時キャッシュ強制無効化追加】
 */
final class EnrollmentStatusChangeService
{
    /*
     * @param Enrollment $enrollment 状態遷移する対象 Enrollment
     * @param mixed $oldStatus 遷移する前の状態 EnrollmentStatus
     * @param mixed $newStatus 遷移した後の状態 EnrollmentStatus
     * @param User $operator 操作者 \App\MOdels\User
     */
    public function recordStatusChange(Enrollment $enrollment, mixed $oldStatus, mixed $newStatus, User $operator): void
    {
        // 引数でEnumオブジェクトが届いた場合でも、プレーンな文字列が届いた場合でも、
        // 両方から安全に生のバリュー（文字列）を取り出せるようにする
        $oldStatusStr = is_object($oldStatus) && isset($oldStatus->value) ? $oldStatus->value : (string) $oldStatus;
        $newStatusStr = is_object($newStatus) && isset($newStatus->value) ? $newStatus->value : (string) $newStatus;

        // 既存の監査ログ記録処理（他メンバーの残した負債を安全に実行）
        Log::info("受講ステータス変更監査ログ: EnrollmentID [{$enrollment->id}] が {$oldStatusStr} から {$newStatusStr} へ遷移しました。操作者: UserID [{$operator->id}]");

        // 【T-A-06核心要件：受講状態の遷移に伴うダッシュボードキャッシュの即時無効化（パージ）】
        // 新規受講登録、合格、不合格、学習中止（Fail）など、システム全域のどの経路から受講状態が遷移しようとも、
        // この共通サービスを通過したら、全体KPIと資格別修了率の2つのキャッシュキーをCache::forget によって
        // メモリ空間から 1 バイト残さず強制パージする。
        Cache::forget((string) config('dashboard.admin_kpi_cache_key'));
        Cache::forget((string) config('dashboard.admin_completion_rate_cache_key'));
    }}
