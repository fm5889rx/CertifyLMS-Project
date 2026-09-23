<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Meeting;

use App\Actions\Meeting\FetchAvailabilityAction;
use App\Enums\CertificationDifficulty;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 👑 【T-A-02：1対1適合】FetchAvailabilityAction 専用の単体テスト。
 */
class FetchAvailabilityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_availability_actionが空き枠計算サービスへと正しくバトンをリレーしてコレクションを返すこと(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);

        $category = CertificationCategory::create([
            'name' => 'Unitテスト資格カテゴリ',
            'slug' => 'test-certification-category',
        ]);

        $certification = Certification::create([
            'name' => 'Unitテスト資格',
            'category_id' => $category->id,
            'difficulty' => CertificationDifficulty::Intermediate,
            'status' => 'published',
            'created_by_user_id' => $student->id,
            'updated_by_user_id' => $student->id,
        ]);

        $enrollment = Enrollment::create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'status' => EnrollmentStatus::Learning->value,
        ]);

        $date = Carbon::tomorrow();

        $mockService = app(MeetingAvailabilityService::class);

        $action = new FetchAvailabilityAction($mockService);
        $result = $action($enrollment, $date);

        $this->assertInstanceOf(Collection::class, $result);
    }
}
