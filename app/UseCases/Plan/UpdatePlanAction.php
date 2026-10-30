<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;

/**
 * 既存の受講プランの基本情報を型安全に更新するユースケース
 */
final class UpdatePlanAction
{
    /**
     * プラン更新ロジックの実行
     *
     * @param Plan $plan 対象のプランモデル
     * @param array<string, mixed> $validatedData
     * @param string $adminId
     * @return bool
     */
    public function execute(Plan $plan, array $validatedData, string $adminId): bool
    {
        $validatedData['updated_by_user_id'] = $adminId;

        return $plan->update($validatedData);
    }
}
