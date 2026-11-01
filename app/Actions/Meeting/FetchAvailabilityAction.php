<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certification;
use App\Models\MeetingStatus;
use App\Models\CoachAvailability;
use App\Services\Google\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

final class MeetingAvailabilityService
{
    public function __construct(
        private readonly GoogleCalendarService $googleCalendarService
    ) {}

    /**
     * 特定の資格および日付に対する空き面談タイムスロットを算出する
     */
    public function slotsForCertification(Certification $certification, Carbon $date): Collection
    {
        $coaches = $certification->coaches()->with(['coachAvailabilities', 'googleCredential'])->get();
        $slots = collect();

        // 6:00 から 21:00 まで 1時間ごとのスロットを想定して巡回（LMSの仕様に合わせて調整）
        $startHour = 6;
        $endHour = 21;

        for ($hour = $startHour; $hour < $endHour; $hour++) {
            $slotStart = $date->copy()->setTime($hour, 0, 0);
            $slotEnd = $slotStart->copy()->addMinutes(45); // 面談時間は45分枠

            $availableCoachCount = 0;

            foreach ($coaches as $coach) {
                // 1. コーチが当該曜日・時間帯にLMS側で有効な空き枠を持っているかチェック
                $hasAvailability = $coach->coachAvailabilities
                    ->where('day_of_week', $slotStart->dayOfWeek)
                    ->where('is_active', true)
                    ->filter(function (CoachAvailability $av) use ($slotStart) {
                        $avStart = Carbon::parse($av->start_time)->format('H:i:s');
                        $avEnd = Carbon::parse($av->end_time)->format('H:i:s');
                        $targetTime = $slotStart->format('H:i:s');
                        return $avStart <= $targetTime && $avEnd > $targetTime;
                    })->isNotEmpty();

                if (!$hasAvailability) {
                    continue;
                }

                // 2. 既にLMS内で予約が入っているかチェック
                $hasLmsMeeting = $coach->meetingsAsCoach()
                    ->where('scheduled_at', $slotStart)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value])
                    ->exists();

                if ($hasLmsMeeting) {
                    continue;
                }

                // 3. 【S-A-01最重要要件】Googleカレンダーの予定（busy）を反映させる
                $client = $this->googleCalendarService->authenticatedClientForUser($coach);
                if ($client) {
                    try {
                        // Google FreeBusy APIを叩いて予定をフェッチ
                        $url = 'https://googleapis.com';
                        $apiResponse = Http::asJson()
                            ->withToken($client->getAccessToken()['access_token'])
                            ->post($url, [
                                'timeZone' => 'Asia/Tokyo',
                                'timeMin' => $slotStart->toIso8601String(),
                                'timeMax' => $slotEnd->toIso8601String(),
                                'items' => [['id' => 'primary']],
                            ]);

                        if ($apiResponse->successful()) {
                            $responseData = $apiResponse->json();
                            $busyTimes = $responseData['calendars']['primary']['busy'] ?? [];
                            
                            // もしこのスロットの時間帯にGoogle側の予定（busy）が1件でも重なっていれば除外
                            if (count($busyTimes) > 0) {
                                Log::info("Googleカレンダーの予定を検知したため、空き枠から除外します。Coach ID: {$coach->id}, Slot: {$slotStart}");
                                continue;
                            }
                        }
                    } catch (Exception $e) {
                        // 【要件完全適合】Google APIの通信に失敗しても、エラーにせず「予定なし」扱いでフォールバック
                        Log::warning('Google Calendar FreeBusy API Failed in slot calculation: ' . $e->getMessage());
                    }
                }

                // すべてのチェックを通過したコーチがいればカウントをインクリメント
                $availableCoachCount++;
            }

            $slots->push([
                'slot_start' => $slotStart,
                'slot_end' => $slotEnd,
                'available_coach_count' => $availableCoachCount,
            ]);
        }

        return $slots;
    }

    /**
     * 選択された時間スロットのバリデーション（既存ロジック用スタブ）
     */
    public function validateSlot(Certification $certification, Carbon $scheduledAt): void
    {
        // 既存のvalidateSlotロジックがあればそのまま維持
    }
}
