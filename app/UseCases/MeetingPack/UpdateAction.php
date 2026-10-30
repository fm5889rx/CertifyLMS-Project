<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

/**
 * 面談パックの基本情報を型安全に更新するユースケース
 */
class UpdateAction
{
    /**
     * @param MeetingPack $plan
     * @param array<string, mixed> $validatedData
     * @param string $adminId
     * @return bool
     */
    public function execute(MeetingPack $plan, array $validatedData, string $adminId): bool
    {
        $validatedData['updated_by_user_id'] = $adminId;
        
        return $plan->update($validatedData);
    }
}
