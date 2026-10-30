<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Support\Str;

/**
 * 新規受講プランを下書きとして型安全に新設するユースケース（横断品質完全準拠版）
 */
final class StorePlanAction
{
    /**
     * プラン新設ロジックの実行
     *
     * @param array<string, mixed> $validatedData フォームリクエストで検証済みのデータ
     * @param string $adminId 操作を実行した管理者のID
     * @return Plan
     */
    public function execute(array $validatedData, string $adminId): Plan
    {
        $validatedData['id'] = (string) Str::ulid();
        
        // ⭕ Enum型安全：Enumオブジェクトのバリュー、または文字列型に完全適合
        $validatedData['status'] = PlanStatus::Draft->value ?? PlanStatus::Draft;
        
        $validatedData['created_by_user_id'] = $adminId;
        $validatedData['updated_by_user_id'] = $adminId;

        return Plan::create($validatedData);
    }
}
