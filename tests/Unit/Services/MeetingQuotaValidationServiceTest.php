<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Meeting;

use App\Services\MeetingQuotaValidationService;
use App\Services\MeetingQuotaService; // 💡 本物の実在クラス
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MeetingQuotaValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function test_残数がある場合はtrueを返し残数ゼロの場合はfalseを返すこと(): void
    {
        // 💡【解決】本物の MeetingQuotaService に残数「3回」と判定させるため、
        // データベース層に max_meetings => 3 を持たせた本物の受講生を先在させます
        $studentWithQuota = User::factory()->create([
            'max_meetings' => 3,
        ]);

        $studentNoQuota = User::factory()->create([
            'max_meetings' => 0,
        ]);

        // 💡【解決】finalクラスのため Mockery を完全パージし、コンテナから本物のサービスを吸引！
        $realQuotaService = app(MeetingQuotaService::class);
        $service = new MeetingQuotaValidationService($realQuotaService);

        // 1. 残数がある場合は true が返ることを指差しアサート検証
        $this->assertTrue($service->hasQuota($studentWithQuota));

        // 2. 残数ゼロの場合は false が返ることを指差しアサート検証
        $this->assertFalse($service->hasQuota($studentNoQuota));
    }
}
