<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CoachAvailability;
use App\Models\User;
use App\Models\UserGoogleCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Mockery as m;
use Tests\TestCase;

/**
 * Google Calendar 連携用テスト（S-A-01 新規追加）
 * 【T-A-04 仕様追加適合：引数クエリ・トークン完全シンクロ決定版】
 */
class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // ファサードの参照のズレを完全にシャットアウト
        Http::clearResolvedInstances();

        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00', 'Asia/Tokyo'));

        $this->coach = User::factory()->create([
            'role' => UserRole::Coach,
            'status' => UserStatus::InProgress,
        ]);

        $this->student = User::factory()->create([
            'role' => UserRole::Student,
            'status' => UserStatus::InProgress,
        ]);
    }

    protected function tearDown(): void
    {
        if (class_exists(m::class)) {
            m::close();
        }
        if (app()->bound('db')) {
            app('db')->disconnect();
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @group external-api
     * 1. 【T-A-04 で変更：アクセストークン期限切れ ➡ 自動リフレッシュ ➡ 物理層同期 ＆ 再試行成功検証】
     */
    public function test_カレンダー情報取得時にアクセストークンが期限切れエラーを起こした場合に裏側で自動リフレッシュが執行され再試行が成功すること(): void
    {
        $this->coach->googleCredential()->create([
            'google_email' => 'coach@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'expired-access-token-123',
            'refresh_token' => 'valid-refresh-token-999',
            'connected_at' => now()->subDays(1),
        ]);

        // URLマッチングを最も安全なクロージャ判定でマウント
        $freeBusyCount = 0;
        Http::fake(function (Request $request) use (&$freeBusyCount) {
            $url = $request->url();

            if (str_contains($url, 'calendar/v3/freeBusy')) {
                $freeBusyCount++;
                if ($freeBusyCount === 1) {
                    return Http::response(['error' => 'Unauthorized'], 401);
                }

                return Http::response([
                    'calendars' => ['primary' => ['busy' => []]],
                ], 200);
            }

            if (str_contains($url, 'googleapis.com') && ! str_contains($url, 'www.')) {
                return Http::response([
                    'access_token' => 'new-fresh-access-token-777',
                    'expires_in' => 3600,
                ], 200);
            }

            return Http::response(['error' => 'Unexpected URL'], 404);
        });

        // 実行
        $response = $this->actingAs($this->coach)
            ->get('/settings/google-calendar/connect');

        // 検証
        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHas('success', 'Googleカレンダーの空き時間を同期しました。');

        $this->coach->unsetRelation('googleCredential');
        $this->assertDatabaseHas('user_google_calendar', [
            'user_id' => $this->coach->id,
            'access_token' => 'new-fresh-access-token-777',
        ]);
    }

    /**
     * 2. 出発ルート正常系検証
     */
    public function test_コーチは_google認可画面へリダイレクトされる(): void
    {
        Socialite::shouldReceive('driver')->with('google')->andReturn($mockDriver = m::mock());
        $mockDriver->shouldReceive('scopes')->andReturn($mockDriver);
        $mockDriver->shouldReceive('with')->andReturn($mockDriver);
        $mockDriver->shouldReceive('redirect')->andReturn(redirect('https://googleapis.com'));

        $response = $this->actingAs($this->coach)
            ->get('/settings/google-calendar');

        $response->assertRedirect('https://googleapis.com');
    }

    /**
     * 3. 認可ガード認可系検証
     */
    public function test_受講生は_google認可画面が表示されず403エラーが返される(): void
    {
        $response = $this->actingAs($this->student)
            ->get('/settings/google-calendar');

        $response->assertStatus(403);
    }

    /**
     * 4. コールバック正常系検証
     */
    public function test_コールバックでトークンが得られ、busy時間枠が取得できる(): void
    {
        $abstractUser = m::mock('Laravel\Socialite\Two\User');
        $abstractUser->shouldReceive('getEmail')->andReturn('coach@example.com');

        $abstractUser->token = 'mock-access-token-123';
        $abstractUser->refreshToken = 'mock-refresh-token';

        Socialite::shouldReceive('driver')->with('google')->andReturn($mockDriver = m::mock());
        $mockDriver->shouldReceive('stateless')->andReturn($mockDriver);
        $mockDriver->shouldReceive('user')->andReturn($abstractUser);

        Http::fake([
            '*' => Http::response([
                'calendars' => [
                    'primary' => [
                        'busy' => [],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->coach)
            ->get('/settings/google-calendar/callback');

        // データベースの物理状態をアサーション
        $this->assertDatabaseHas('user_google_calendar', [
            'user_id' => $this->coach->id,
            'google_email' => 'coach@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'mock-access-token-123',
            'refresh_token' => 'mock-refresh-token',
        ]);

        $response->assertRedirect(route('settings.availability.index'));
    }

    /**
     * 5. 連携解除正常系検証
     */
    public function test_連携解除でトークン情報が物理削除される(): void
    {
        UserGoogleCalendar::create([
            'user_id' => $this->coach->id,
            'google_email' => 'coach@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'old-token',
            'refresh_token' => 'old-refresh',
        ]);

        $availability = CoachAvailability::create([
            'coach_id' => $this->coach->id,
            'day_of_week' => 1,
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->coach)
            ->delete('/settings/google-calendar');

        $this->assertDatabaseMissing('user_google_calendar', [
            'user_id' => $this->coach->id,
        ]);

        $this->assertTrue((bool) $availability->refresh()->is_active);
        $response->assertRedirect(route('settings.availability.index'));
    }

    /**
     * @group external-api
     * 6. 【T-A-04 要件適合：リフレッシュトークン失効時（400エラー等）の安全フォールバック検証】
     */
    public function test_カレンダー連携のリフレッシュトークン自体が完全に失効し自動リフレッシュに失敗した場合は安全に設定画面へエラーメッセージ付きでフォールバックリダイレクトされること(): void
    {
        $this->coach->googleCredential()->create([
            'google_email' => 'coach@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'expired-token',
            'refresh_token' => 'dead-refresh-token',
            'connected_at' => now()->subDays(1),
        ]);

        // 1回目の freeBusy 通信に対して 401 を返し、2回目の OAuth2 トークン交換通信に対して
        // Google側が「400 BadRequest (invalid_grant: トークン死亡)」を返す挙動をシミュレート
        Http::fake([
            'https://googleapis.com' => Http::response(['error' => ['message' => 'Invalid Token']], 401),
            'https://googleapis.com' => Http::response(['error' => ['message' => 'invalid_grant']], 400),
        ]);

        $response = $this->actingAs($this->coach)
            ->get('/settings/google-calendar/connect');

        // システムが500クラッシュせず、リフレッシュ失敗のエラーメッセージをセッションに抱えて安全に戻ることを検証
        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHasErrors(['error']);
    }

    /**
     * @group external-api
     * 7. 【T-A-04 要件適合：実機403（API無効化）や500サーバーエラー発生時の即死クラッシュ完封検証】
     */
    public function test_googleカレンダー_ap_iから403や500系明確例外エラーが帰ってきた場合もシステムが安全に検閲早期リターンすること(): void
    {
        $this->coach->googleCredential()->create([
            'google_email' => 'coach@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'valid-token-123',
            'refresh_token' => 'refresh-token-123',
            'connected_at' => now(),
        ]);

        // 実機ログ（）と同一の、Google 側から直接 403 Forbidden が突き返される挙動を模擬
        Http::fake([
            'https://www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'Google Calendar API has not been used in project before or it is disabled.',
                    'status' => 'PERMISSION_DENIED',
                ],
            ], 403),
        ]);

        $response = $this->actingAs($this->coach)
            ->get('/settings/google-calendar/connect');

        // 403メッセージをすり抜けさせず、安全に「Google連携エラー: 」をフロントへ弾き返すことを検証
        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHasErrors(['error']);
    }
}
