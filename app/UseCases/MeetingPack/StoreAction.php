<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Support\Str;

/**
 * 面談パックを下書きとして安全に新設するユースケース
 */
class StoreAction
{
    /**
     * @param array<string, mixed> $validatedData
     * @param string $adminId
     * @return MeetingPack
     */
    public function execute(array $validatedData, string $adminId): MeetingPack
    {
        $validatedData['id'] = (string) Str::ulid();
        $validatedData['status'] = MeetingPackStatus::Draft->value ?? MeetingPackStatus::Draft;
        $validatedData['created_by_user_id'] = $adminId;
        $validatedData['updated_by_user_id'] = $adminId;

        return MeetingPack::create($validatedData);
    }
}
