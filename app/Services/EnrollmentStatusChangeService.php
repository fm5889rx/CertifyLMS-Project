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
     * @param mixed $fromStatus 遷移する前の状態 EnrollmentStatus
     * @param mixed $toStatus 遷移した後の状態 EnrollmentStatus
     * @param ?User $changedBy 操作者 \App\Models\User
     * @param mixed $reason 状態遷移した理由 EnrollmentStatusLog.change_reason
     */
    public function recordStatusChange(
        Enrollment $enrollment,
        mixed $fromStatus,
        mixed $toStatus,
        ?User $changedBy,
        mixed $reason = ''): void
    {
        // データベースへのログ保存
        $enrollment->statusLogs()->create([
            'from_status'        => is_object($fromStatus) ? $fromStatus->value : $fromStatus,
            'to_status'          => is_object($toStatus) ? $toStatus->value : $toStatus,
            'changed_by_user_id' => $changedBy ? $changedBy->id : null,
            'changed_reason'     => is_object($reason) ? $reason->value : $reason,
            'changed_at'         => now(),
        ]);

        // 【T-A-06核心要件：受講状態の遷移に伴うダッシュボードキャッシュの即時無効化（パージ）】
        // 新規受講登録、合格、不合格、学習中止（Fail）など、システム全域のどの経路から受講状態が遷移しようとも、
        // この共通サービスを通過したら、全体KPIと資格別修了率の2つのキャッシュキーをCache::forget によって
        // メモリ空間から 1 バイト残さず強制パージする。
        cache()->forget((string) config('dashboard.admin_kpi_cache_key'));
        cache()->forget((string) config('dashboard.admin_completion_rate_cache_key'));
    }
}
