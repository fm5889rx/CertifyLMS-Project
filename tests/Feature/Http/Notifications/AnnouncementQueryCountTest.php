<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notifications;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnnouncementQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcement_history_query_count_does_not_grow_with_history_size(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $targetUser = User::factory()->student()->inProgress()->create();
        $this->createAnnouncements($admin, $certification, $targetUser, 3);
        $this->actingAs($admin);

        $baseline = $this->countQueriesFor(fn () => $this->get(route('admin.announcements.index'))->assertOk());
        $this->createAnnouncements($admin, $certification, $targetUser, 17);
        $scaled = $this->countQueriesFor(fn () => $this->get(route('admin.announcements.index'))->assertOk());

        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "Announcement history query count grew with history size ({$baseline} → {$scaled}); eager-load the sender relation.",
        );
    }

    private function createAnnouncements(
        User $admin,
        Certification $certification,
        User $targetUser,
        int $count,
    ): void {
        foreach (range(1, $count) as $index) {
            $targetType = match ($index % 3) {
                0 => AnnouncementTargetType::AllStudents,
                1 => AnnouncementTargetType::Certification,
                default => AnnouncementTargetType::User,
            };
            Announcement::create([
                'id' => (string) Str::ulid(),
                'title' => "Announcement {$index}",
                'body' => 'Announcement body',
                'target_type' => $targetType,
                'target_id' => match ($targetType) {
                    AnnouncementTargetType::Certification => $certification->id,
                    AnnouncementTargetType::User => $targetUser->id,
                    AnnouncementTargetType::AllStudents => null,
                },
                'dispatched_count' => 0,
                'created_by_user_id' => $admin->id,
                'dispatched_at' => now(),
            ]);
        }
    }

    private function countQueriesFor(\Closure $request): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $request();

        return $count;
    }
}
