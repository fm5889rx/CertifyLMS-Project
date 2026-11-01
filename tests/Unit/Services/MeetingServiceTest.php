<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Meeting;

use App\Services\MeetingService;
use App\Models\Meeting;
use App\Enums\MeetingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MeetingServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function test_面談ステータスのアクティブ判定が正常に稼働すること(): void
    {
        $meeting = Meeting::factory()->create(['status' => MeetingStatus::Reserved]);
        $service = new MeetingService();

        $this->assertTrue($service->isActive($meeting));
    }
}
