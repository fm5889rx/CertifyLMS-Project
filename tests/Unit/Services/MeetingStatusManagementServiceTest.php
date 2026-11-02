<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Services\MeetingStatusManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MeetingStatusManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function test_面談を正常にキャンセル状態に遷移できること(): void
    {
        $meeting = Meeting::factory()->create(['status' => MeetingStatus::Reserved]);
        $service = new MeetingStatusManagementService;

        $result = $service->cancel($meeting);

        $this->assertEquals(MeetingStatus::Canceled, $result->refresh()->status);
    }
}
