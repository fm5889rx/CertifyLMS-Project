<?php

declare(strict_types=1);

namespace App\UseCases\Google;

use App\Exceptions\Google\GoogleApiException;
use App\Models\CoachAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

final class SyncGoogleCalendarAction
{
    /**
     * Google FreeBusy APIから予定をフェッチし同期する
     *
     * @throws GoogleApiException
     */
    public function execute(User $coach, ?string $accessToken = null): void
    {
        $currentCoachId = auth()->id();
        $credential = $coach->googleCredential;
        $token = $accessToken ?? $credential?->access_token;

        if (! $token) {
            throw new GoogleApiException('Google Calendar アクセストークンが見つかりません。');
        }

        // 【実機仕様優先】Google APIの正確なエンドポイント（bは小文字）に完全固定
        $url = 'https://www.googleapis.com/calendar/v3/freeBusy';

        // 1回目の通信を執行
        $apiResponse = Http::asJson()
            ->withToken($token)
            ->post($url, [
                'timeZone' => 'Asia/Tokyo',
                'timeMin' => Carbon::now()->startOfDay()->toIso8601String(),
                'timeMax' => Carbon::now()->addDays(7)->endOfDay()->toIso8601String(),
                'items' => [
                    ['id' => 'primary'],
                ],
            ]);

        // 401未認可時の自動リフレッシュ回路
        if (! $apiResponse->successful() && $apiResponse->status() === 401) {
            if ($credential && $credential->refresh_token) {

                $tokenResponse = Http::asJson()->post('https://googleapis.com', [
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                    'refresh_token' => $credential->refresh_token,
                    'grant_type' => 'refresh_token',
                ]);

                if ($tokenResponse->successful()) {
                    $newTokenData = $tokenResponse->json();
                    $token = $newTokenData['access_token'];

                    $credential->update([
                        'access_token' => $token,
                        'connected_at' => Carbon::now(),
                    ]);

                    // 2回目の本通信（小文字のurlでリトライ）
                    $apiResponse = Http::asJson()
                        ->withToken($token)
                        ->post($url, [
                            'timeZone' => 'Asia/Tokyo',
                            'timeMin' => Carbon::now()->startOfDay()->toIso8601String(),
                            'timeMax' => Carbon::now()->addDays(7)->endOfDay()->toIso8601String(),
                            'items' => [['id' => 'primary']],
                        ]);

                    if (! $apiResponse->successful()) {
                        $errorData = $apiResponse->json();
                        $retryMessage = $errorData['error']['message'] ?? '再試行通信に失敗しました。';
                        throw new GoogleApiException('Google連携リトライエラー: '.$retryMessage);
                    }
                } else {
                    throw new GoogleApiException('Google認証リフレッシュ失敗: リフレッシュトークンが失効しています。再連携してください。');
                }
            } else {
                throw new GoogleApiException('Google連携情報が見つかりません。');
            }
        }

        if (! $apiResponse->successful()) {
            $errorData = $apiResponse->json();
            $googleMessage = $errorData['error']['message'] ?? 'Google APIで予期せぬエラーが発生しました。';
            throw new GoogleApiException("Google連携エラー({$apiResponse->status()}): ".$googleMessage);
        }

        $responseData = $apiResponse->json();
        if (! isset($responseData['calendars']['primary']['busy'])) {
            throw new GoogleApiException('Google Calendar API のレスポンスに期待されるデータが含まれていません。');
        }

        $busyTimes = $responseData['calendars']['primary']['busy'];

        CoachAvailability::where('coach_id', $currentCoachId)->delete();

        foreach ($busyTimes as $busyTime) {
            CoachAvailability::create([
                'coach_id' => $currentCoachId,
                'day_of_week' => Carbon::parse($busyTime['start'])->dayOfWeek,
                'start_time' => Carbon::parse($busyTime['start']),
                'end_time' => Carbon::parse($busyTime['end']),
                'is_active' => false,
            ]);
        }

        $startOfDay = Carbon::now()->setTime(6, 0, 0);
        $endOfDay = Carbon::now()->setTime(21, 0, 0);
        for ($day = 0; $day < 7; $day++) {
            $currentDay = $startOfDay->copy()->addDays($day);
            $currentEndOfDay = $endOfDay->copy()->addDays($day);

            $dailyBusyTimes = array_filter($busyTimes, function ($busyTime) use ($currentDay) {
                return Carbon::parse($busyTime['start'])->isSameDay($currentDay);
            });

            $lastEndTime = $currentDay->copy()->setTime(6, 0);
            foreach ($dailyBusyTimes as $busyTime) {
                $busyStart = Carbon::parse($busyTime['start']);
                if ($lastEndTime->lt($busyStart)) {
                    CoachAvailability::create([
                        'coach_id' => $currentCoachId,
                        'day_of_week' => $currentDay->dayOfWeek,
                        'start_time' => $lastEndTime,
                        'end_time' => $busyStart,
                        'is_active' => true,
                    ]);
                }
                $lastEndTime = Carbon::parse($busyTime['end']);
            }

            if ($lastEndTime->lt($currentEndOfDay)) {
                CoachAvailability::create([
                    'coach_id' => $currentCoachId,
                    'day_of_week' => $currentDay->dayOfWeek,
                    'start_time' => $lastEndTime,
                    'end_time' => $currentEndOfDay,
                    'is_active' => true,
                ]);
            }
        }
    }
}
