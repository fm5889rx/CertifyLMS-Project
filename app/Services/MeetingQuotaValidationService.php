<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\MeetingQuotaService;

final class MeetingQuotaValidationService
{
    public function __construct(
        private readonly MeetingQuotaService $quotaService
    ) {}

    /**
     * 受講生が面談予約可能なチケット残数を持っているか確認
     */
    public function hasQuota(User $student): bool
    {
        return $this->quotaService->remaining($student) > 0;
    }
}
