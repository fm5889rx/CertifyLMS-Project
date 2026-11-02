<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Meeting;

use App\Models\User;
use App\Services\MeetingQuotaService;
use App\Services\MeetingQuotaValidationService;
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
        $studentWithQuota = User::factory()->create([
            'max_meetings' => 3,
        ]);

        $studentNoQuota = User::factory()->create([
            'max_meetings' => 0,
        ]);

        $realQuotaService = app(MeetingQuotaService::class);
        $service = new MeetingQuotaValidationService($realQuotaService);

        $this->assertTrue($service->hasQuota($studentWithQuota));

        $this->assertFalse($service->hasQuota($studentNoQuota));
    }
}
