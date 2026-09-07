<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\UserGoogleCalendar;
use Carbon\Carbon;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL as URLFacade;
use App\Models\CoachAvailability;

/**
 * Google Calendar 連携用コントローラー（S-A-01追加）
 */
class GoogleCalendarController extends Controller
{
    /**
     * Google Calendar からカレンダー情報を取得する
     * ここでは、Google Calendar APIとの連携処理を行うコードを記述します。
     */
    public function connect(?String $accessToken = null)
    {
        // コーチ以外なら 403 エラーにする
        if (!auth()->user()->isCoach()) {
            abort(403, 'コーチ専用の機能です。');
        }

        // アクセストークンが渡されていない場合は、ユーザーのカレンダー連携情報から取得する
        $token = $accessToken ?? auth()->user()->googleCredentials?->access_token;

        // アクセストークンが取得できない場合は、エラーを返す
        if(!$token) {
            return redirect(route('settings.availability.index'))
                ->withErrors(['error' => 'Google Calendar アクセストークンが見つかりません。']);
        }

        // Google Calendar APIのエンドポイントURL
        $url = 'https://www.googleapis.com/calendar/v3/freeBusy';

        // Google Calendar APIにリクエストを送信して、カレンダー情報を取得する
        $apiResponse = Http::retry(1, 1000)->asJson()
            ->withToken($token)
            ->post($url, [
                'timeZone' => 'Asia/Tokyo',
                'timeMin'  => Carbon::now()->startOfDay()->toIso8601String(),
                'timeMax'  => Carbon::now()->addDays(7)->endOfDay()->toIso8601String(),
                'items'    => [
                    ['id' => 'primary'],
                ],
            ]);

    if ($apiResponse->failed()) {
            Log::error('Google Calendar API request failed', [
                'response' => $apiResponse->body(),
            ]);
            return redirect(route('settings.availability.index'))
                ->withErrors(['error' => 'Google Calendar API へのリクエストが失敗しました。']);
        }

        // APIレスポンスの内容を取得
        $responseData = $apiResponse->json();

        if (!isset($responseData['calendars']['primary']['busy'])) {
            Log::error('Google Calendar API response does not contain expected data', [
                'response' => $responseData,
            ]);

            foreach ($responseData as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    Log::error("Response key: $key, value: " . json_encode($value));
                } else {
                    Log::error("Response key: $key, value: $value");
                }

                CoachAvailability::create([
                    'user_id' => auth()->id(),
                    'start_time' => $value['start'] ?? Carbon::now()->setTime(9, 0),
                    'end_time' => $value['end'] ?? Carbon::now()->setTime(17, 0),
                    'is_active' => true,
                ]);
            }

            return redirect(route('settings.availability.index'))
                ->withErrors(['error' => 'Google Calendar API のレスポンスに期待されるデータが含まれていません。']);
        }

        // Google Calendar APIのレスポンスから、空き時間を取得
        $busyTimes = $responseData['calendars']['primary']['busy'];

        // 一旦、既存の空き時間を削除する
        CoachAvailability::where('coach_id', auth()->id())->delete();

        // 取得したbusy時間をデータベースに保存する
        foreach ($busyTimes as $busyTime) {
            CoachAvailability::create([
                'coach_id' => auth()->id(),
                'day_of_week' => Carbon::parse($busyTime['start'])->dayOfWeek,
                'start_time' => Carbon::parse($busyTime['start']),
                'end_time' => Carbon::parse($busyTime['end']),
                'is_active' => false, // Google Calendarで埋まっている時間は非アクティブにする
            ]);
        }

        // 残りの期間を予約可能時間枠としてデータベースに保存する
        $startOfDay = Carbon::now()->setTime(6, 0, 0); // 6:00 AM
        $endOfDay = Carbon::now()->setTime(21, 0, 0);  // 9:00 PM
        // 7日間のループ
        for ($day = 0; $day < 7; $day++) {
            // その日の開始時間と終了時間を設定
            $currentDay = $startOfDay->copy()->addDays($day);
            $currentEndOfDay = $endOfDay->copy()->addDays($day);

            // その日のbusy時間を取得
            $dailyBusyTimes = array_filter($busyTimes, function ($busyTime) use ($currentDay) {
                return Carbon::parse($busyTime['start'])->isSameDay($currentDay);
            });

            // その日の空き時間を計算
            $lastEndTime = $currentDay->copy()->setTime(6, 0);
            foreach ($dailyBusyTimes as $busyTime) {
                $busyStart = Carbon::parse($busyTime['start']);
                if ($lastEndTime->lt($busyStart)) {
                    CoachAvailability::create([
                        'coach_id' => auth()->id(),
                        'day_of_week' => $currentDay->dayOfWeek,
                        'start_time' => $lastEndTime,
                        'end_time' => $busyStart,
                        'is_active' => true,
                    ]);
                }
                $lastEndTime = Carbon::parse($busyTime['end']);
            }

            // 最後のbusy時間の後の空き時間を追加
            if ($lastEndTime->lt($currentEndOfDay)) {
                CoachAvailability::create([
                    'coach_id' => auth()->id(),
                    'day_of_week' => $currentDay->dayOfWeek,
                    'start_time' => $lastEndTime,
                    'end_time' => $currentEndOfDay,
                    'is_active' => true,
                ]);
            }
        }

        // 設定画面に戻る
        return redirect()->route('settings.availability.index')
            ->with('success', 'Googleカレンダーの空き時間を同期しました！');
    }

    /**
     * Google Calendar 連携を解除する (S-A-01追加)
     */
    public function destroy(Request $request): RedirectResponse
    {
        // 1. コーチ以外なら 403 エラーにする
        if (!auth()->user()->isCoach()) {
            abort(403, 'コーチ専用の機能です。');
        }

        // 2. 自作モデルの disconnect を叩いて、トークンやメールアドレスを安全に削除
        auth()->user()->googleCredential()->delete();

        // 3. Google連携によって沈められていた時間枠のフラグを一括で true に完全原状復帰
        CoachAvailability::where('coach_id', auth()->id())
            ->update(['is_active' => true]);

        // 4. 設定画面へ成功リダイレクト
        return redirect()->route('settings.availability.index')
            ->with('success', 'Googleカレンダーの連携を解除しました。');
    }

    /**
     * Google Calendar 連携のコールバック処理
     * ここでは、Google Calendar APIからのコールバックを受け取り、
     * アクセストークンやリフレッシュトークンを取得し、データベースに保存する処理を行う。
     */
    public function callback(Request $request): RedirectResponse
    {
        // コーチ以外なら 403 エラーにする
        if (!auth()->user()->isCoach()) {
            abort(403, 'コーチ専用の機能です。');
        }

        // Google Calendar 連携のコールバック処理を実装する
        $googleUser = Socialite::driver('google')->stateless()->user();

        auth()->user()->googleCredential()->updateOrCreate([],
            [
                'google_email' => $googleUser->getEmail(),
                'calendar_id' => 'primary', // デフォルトのカレンダーIDを使用
                'connected_at' => Carbon::now(),
                'access_token' => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken,
            ]
        );

        // Google Calendar 連携情報を更新する
        $this->connect($googleUser->token);

        return redirect()->route('settings.availability.index')
            ->with(['success' => 'Google Calendar 連携が成功しました。']);
    }

    /**
     * Google Calendar 連携のリダイレクト処理
     */
    public function redirect(Request $request): RedirectResponse
    {
        // コーチ以外なら 403 エラーにする
        if (!auth()->user()->isCoach()) {
            abort(403, 'コーチ専用の機能です。');
        }

        return Socialite::driver('google')
            ->scopes(['https://www.googleapis.com/auth/calendar'])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])
            ->redirect();
    }
}
