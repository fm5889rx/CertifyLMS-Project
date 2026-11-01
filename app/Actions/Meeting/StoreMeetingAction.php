<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Services\CoachMeetingLoadService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use App\Services\Google\GoogleCalendarService; // S-A-01追加
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Google\Service\Calendar\Event;
use Exception;

final class StoreMeetingAction
{
    public function __construct(
        private MeetingAvailabilityService $availabilityService,
        private CoachMeetingLoadService $coachLoadService,
        private MeetingQuotaService $quotaService,
        private ConsumeQuotaAction $consumeAction,
        private GoogleCalendarService $calendarService // S-A-01追加
    ) {}

    public function __invoke(Enrollment $enrollment, Carbon $scheduledAt, string $topic): Meeting
    {
        $student = $enrollment->user;

        return DB::transaction(function () use ($enrollment, $student, $scheduledAt, $topic) {
            if ($this->quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $this->availabilityService->validateSlot($enrollment->certification, $scheduledAt);

            $candidates = $this->findAvailableCoaches($enrollment->certification, $scheduledAt);
            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $this->coachLoadService->leastLoadedCoach($candidates);

            try {
                $meeting = Meeting::create([
                    'enrollment_id' => $enrollment->id,
                    'coach_id' => $coach->id,
                    'student_id' => $student->id,
                    'scheduled_at' => $scheduledAt,
                    'status' => MeetingStatus::Reserved,
                    'topic' => $topic,
                    'meeting_url_snapshot' => $coach->meeting_url,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new MeetingNoAvailableCoachException($e);
            }

            $transaction = ($this->consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            // --- 【S-A-01: Googleカレンダー連携処理】 ---
            $client = $this->calendarService->authenticatedClientForUser($coach);
            if ($client) {
                try {
                    $calendarService = new \Google\Service\Calendar($client);
                    
                    $event = new Event([
                        'summary' => '【LMS面談】' . $student->name . '様',
                        'description' => 'トピック: ' . $topic . "\n面談URL: " . ($coach->meeting_url ?? '未設定'),
                        'start' => [
                            'dateTime' => $scheduledAt->toRfc3339String(),
                            'timeZone' => 'Asia/Tokyo',
                        ],
                        'end' => [
                            'dateTime' => $scheduledAt->copy()->addMinutes(45)->toRfc3339String(), // 枠に合わせて設定
                            'timeZone' => 'Asia/Tokyo',
                        ],
                    ]);

                    $createdEvent = $calendarService->events->insert('primary', $event);
                    
                    // 動的プロパティまたは識別子退避用カラムを利用してEvent IDを永続化
                    $meeting->google_event_id = $createdEvent->getId();
                    $meeting->save();
                    
                } catch (Exception $e) {
                    // APIが失敗してもLMSの予約コミットは正常に完了させる（フォールバック）
                    Log::error('Google Calendar Event Insert Failed: ' . $e->getMessage());
                }
            }

            return $meeting->fresh();
        });
    }

    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time) {
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get();
    }
}
