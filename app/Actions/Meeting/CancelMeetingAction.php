<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use App\Services\Google\GoogleCalendarService; // S-A-01追加
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

final class CancelMeetingAction
{
    public function __construct(
        private RefundQuotaAction $refundAction,
        private GoogleCalendarService $calendarService // S-A-01追加
    ) {}

    public function __invoke(Meeting $meeting, User $actor): void
    {
        DB::transaction(function () use ($meeting, $actor) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            // --- 【S-A-01: Googleカレンダー連動削除処理】 ---
            $coach = $locked->coach;
            if ($locked->google_event_id) {
                $client = $this->calendarService->authenticatedClientForUser($coach);
                if ($client) {
                    try {
                        $calendarService = new \Google\Service\Calendar($client);
                        $calendarService->events->delete('primary', $locked->google_event_id);
                    } catch (Exception $e) {
                        // 連動削除APIが失敗しても、LMS内のキャンセル手続きは正常に進める（フォールバック）
                        Log::warning('Google Calendar Event Delete Failed: ' . $e->getMessage());
                    }
                }
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            ($this->refundAction)($meeting->student, $meeting->id);
        });
    }
}
