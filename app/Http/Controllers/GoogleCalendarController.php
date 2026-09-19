<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CoachAvailability;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * Google Calendar 連携用コントローラー（S-A-01追加）
 * 【T-A-04修正版】
 * GoogleCalendarControllerTest の実装により判明した問題を修正。
 */
class GoogleCalendarController extends Controller
{
    /**
     * Google Calendar からカレンダー情報を取得する
     * ここでは、Google Calendar APIとの連携処理を行うコードを記述します。
     */
    public function connect(?string $accessToken = null): RedirectResponse
    {
        // コーチ以外なら 403 エラーにする
        if (!auth()->user()->isCoach()) {
            abort(403, 'コーチ専用の機能です。');
        }

        // アクセストークンが渡されていない場合は、ユーザーのカレンダー連携情報から取得する
        $token = $accessToken ?? auth()->user()->googleCredential?->access_token;
        Log::info('引数トークン：' . $accessToken . 'トークンレコード：' . auth()->user()->googleCredential?->access_token . '解析トークン：' . $token);

        // アクセストークンが取得できない場合は、エラーを返す
        if (!$token) {
            return redirect(route('settings.availability.index'))
                ->withErrors(['error' => 'Google Calendar アクセストークンが見つかりません。']);
        }

        // Google Calendar APIのエンドポイントURL
        $url = 'https://www.googleapis.com/calendar/v3/freeBusy';

        // 📄 app/Http/Controllers/GoogleCalendarController.php の 50行目〜121行目付近を完全置換！

        // 1回目の通信を執行
        Log::info('--- [ログ①] 1回目の freeBusy 通信を開始します ---');
        $apiResponse = Http::asJson()
            ->withToken($token)
            ->post($url, [
                'timeZone' => 'Asia/Tokyo',
                'timeMin'  => Carbon::now()->startOfDay()->toIso8601String(),
                'timeMax'  => Carbon::now()->addDays(7)->endOfDay()->toIso8601String(),
                'items'    => [
                    ['id' => 'primary'],
                ],
            ]);
        Log::info('--- [デバッグ] レスポンスの生データ: ' . $apiResponse->body());
        Log::info('--- [ログ②] 1回目のステータス結果: ' . $apiResponse->status() . ' ---');

        // 👑 【T-A-04極限治療：400・403・401・500系 全外部例外パケット一元検閲エンジン】
        // 💡 1回目のレスポンスステータスに応じて、本番環境の全域の死角をミリ単位で型安全に完全防衛！！！
        if (!$apiResponse->successful()) {
            $status = $apiResponse->status();
            $errorData = $apiResponse->json();
            $googleMessage = $errorData['error']['message'] ?? 'Google APIで予期せぬエラーが発生しました。';

            // 🎯 Aパターン：401 Unauthorized（トークン期限切れ）の場合のみ、自動リフレッシュ回路をキック！
            if ($status === 401) {
                Log::info('Google Calendar アクセストークンの期限切れを検知。リフレッシュトークンを用いて自動更新を執行します。');

                $credential = auth()->user()->googleCredential;

                if ($credential && $credential->refresh_token) {
                    Log::info('--- [ログ③] トークン更新APIを発行します。送信先: https://googleapis.com ---');

                    $tokenResponse = Http::asJson()->post('https://googleapis.com', [
                        'client_id'     => config('services.google.client_id'),
                        'client_secret' => config('services.google.client_secret'),
                        'refresh_token' => $credential->refresh_token,
                        'grant_type'    => 'refresh_token',
                    ]);

                    if ($tokenResponse->successful()) {
                        $newTokenData = $tokenResponse->json();
                        $newToken = $newTokenData['access_token'];

                        // データベース物理層へ最新のトークンをその場で上書き永続化
                        $credential->update([
                            'access_token' => $newToken,
                            'connected_at' => Carbon::now(),
                        ]);

                        // 👑 【2回目の本通信（再試行リトライ）の執行】
                        $apiResponse = Http::asJson()
                            ->withToken($newToken)
                            ->post($url, [
                                'timeZone' => 'Asia/Tokyo',
                                'timeMin'  => Carbon::now()->startOfDay()->toIso8601String(),
                                'timeMax'  => Carbon::now()->addDays(7)->endOfDay()->toIso8601String(),
                                'items'    => [['id' => 'primary']],
                            ]);

                        // リトライの結果を再ロード
                        $responseData = $apiResponse->json();

                        // リトライ通信すら失敗した場合は、安全に例外ガードへフォールバック
                        if (!$apiResponse->successful()) {
                            $retryMessage = $responseData['error']['message'] ?? '再試行通信に失敗しました。';
                            return redirect(route('settings.availability.index'))
                                ->withErrors(['error' => 'Google連携リトライエラー: ' . $retryMessage]);
                        }
                    } else {
                        Log::error('❌ トークン更新APIが失敗しました。レスポンス: ', ['body' => $tokenResponse->body()]);
                        return redirect(route('settings.availability.index'))
                            ->withErrors(['error' => 'Google認証リフレッシュ失敗: リフレッシュトークンが失効しています。再連携してください。']);
                    }
                } else {
                    Log::warning('⚠️ データベースに googleCredential または refresh_token が存在しませんでした。');
                    return redirect(route('settings.availability.index'))
                        ->withErrors(['error' => 'Google連携情報が見つかりません。']);
                }
            }
            // Bパターン：400 (BadRequest) / 403 (Forbidden: API無効) / 500 (ServerError) の場合
            // 下側のパースループへ生パケットを突入させてクラッシュさせるのを完全封殺。
            // Google側が返してきた生のメッセージ（例: API has not been used...）を掴み取り、
            // 100% フロント画面へエラーフラッシュ付きで安全にリダイレクトする
            else {
                Log::error("❌ Google Calendar API 側から致命的な例外エラーを検知しました。Status: {$status}, Message: {$googleMessage}");

                return redirect(route('settings.availability.index'))
                    ->withErrors(['error' => "Google連携エラー({$status}): " . $googleMessage]);
            }
        }

        // 上記の!successful()の巨大な網をくぐり抜けてきたパケットのみ、安全にJsonデータをデプロイ
        $responseData = $apiResponse->json();

        // 境界値チェック：正常ステータス（200）を返しながら、中身の構造が壊れている時の最終セーフティネット
        if (!isset($responseData['calendars']['primary']['busy'])) {
            Log::error('Google Calendar API response does not contain expected data', [
                'response' => $responseData,
            ]);

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
                'is_active' => false,
            ]);
        }

        // 残りの期間を予約可能時間枠としてデータベースに保存する
        $startOfDay = Carbon::now()->setTime(6, 0, 0); // 6:00 AM
        $endOfDay = Carbon::now()->setTime(21, 0, 0);  // 9:00 PM
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
                        'coach_id' => auth()->id(),
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
            ->with('success', 'Googleカレンダーの空き時間を同期しました。');
    }

    /**
     * Google Calendar 連携を解除する (S-A-01追加)
     */
    public function destroy(Request $request): RedirectResponse
    {
        if (!auth()->user()->isCoach()) {
            abort(403, 'コーチ専用の機能です。');
        }

        auth()->user()->googleCredential()->delete();

        CoachAvailability::where('coach_id', auth()->id())
            ->update(['is_active' => true]);

        return redirect()->route('settings.availability.index')
            ->with('success', 'Googleカレンダーの連携を解除しました。');
    }

    /**
     * Google Calendar 連携のコールバック処理
     * 【T-A-04 本番コード煮詰め直し：2回目認可時の refresh_token null 上書き破壊バグの完全構造封殺】
     */
    public function callback(Request $request): RedirectResponse
    {
        // コーチ以外なら 403 エラーにする
        if (!auth()->user()->isCoach()) {
            abort(403, 'コーチ専用の機能です。');
        }

        // Google Calendar 連携のコールバック処理を執行
        $googleUser = Socialite::driver('google')->stateless()->user();

        // 既存の連携レコードを物理層から一度スキャン
        $existingCredential = auth()->user()->googleCredential;

        // 【OAuth2セキュリティ規約適合】：
        // Googleの仕様上、2回目以降の認可フローでは refresh_token が null で返却されるため、
        // null が届いた場合は、データベース側に実在している既存の有効な refresh_token を引き継いで保護する
        $refreshToken = $googleUser->refreshToken ?? $existingCredential?->refresh_token;

        // もしデータベースにも存在せず、Googleからも届かなかった場合は、リフレッシュトークンが完全に
        // 失われているため、強制的に「強制再同意（prompt=consent）」画面へ突き返して再吸引します。
        if (!$refreshToken) {
            Log::warning('⚠️ リフレッシュトークンが完全に失効しているため、強制再認可画面へリダイレクトします。');
            return redirect()->route('google-calendar.redirect');
        }

        auth()->user()->googleCredential()->updateOrCreate(
            [],
            [
                'google_email'  => $googleUser->getEmail(),
                'calendar_id'   => 'primary',
                'connected_at'  => Carbon::now(),
                'access_token'  => $googleUser->token,
                'refresh_token' => $refreshToken, 
            ]
        );

        // 【T-A-04根本治療：2重リダイレクトメッセージ上書き摩擦の完全窒息パージ！！！】
        // 内部の $this->connect() メソッド自身が生成した本物の Response（成功・エラー問わず）をフロントへ返却
        return $this->connect($googleUser->token);
    }

    /**
     * Google Calendar 連携のリダイレクト処理
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (!auth()->user()->isCoach()) {
            abort(403, 'コーチ専用の機能です。');
        }

        return Socialite::driver('google')
            ->scopes(['https://googleapis.com'])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])
            ->redirect();
    }
}
