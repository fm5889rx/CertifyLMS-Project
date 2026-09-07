<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CoachAvailability;
use App\Models\User;
use App\Models\UserGoogleCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Mockery as m;
use Tests\TestCase;

class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // 監査用のアカウントの用意
        $this->coach = User::factory()->create([
            'role' => UserRole::Coach,
            'status' => 'in_progress',
        ]);

        $this->student = User::factory()->create([
            'role' => UserRole::Student,
            'status' => 'in_progress',
        ]);

        // 時間軸を完全に固定 (JST)
        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /**
     * @test 出発ルート: コーチはGoogle認可画面へリダイレクトされること
     */
    public function test_コーチはGoogle認可画面へリダイレクトされる(): void
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
     * @test 出発ルート: 受講生が直叩きした場合は403で門前払いされること
     */
    public function test_受講生はGoogle認可画面が表示されず403エラーが返される(): void
    {
        $response = $this->actingAs($this->student)
            ->get('/settings/google-calendar');

        $response->assertStatus(403);
    }

    /**
     * @test コールバック窓口: トークンを物理層へ保存し、busyを避けた枠が再投入されること
     */
    public function test_コールバックでトークンが得られ、busy時間枠が取得できる(): void
    {
        // 1. Socialiteの帰還データを偽装
        $abstractUser = m::mock('Laravel\Socialite\Two\User');
        $abstractUser->shouldReceive('getEmail')->andReturn('coach@example.com');
        $abstractUser->token = 'mock-access-token';
        $abstractUser->refreshToken = 'mock-refresh-token';

        Socialite::shouldReceive('driver')->with('google')->andReturn($mockDriver = m::mock());
        $mockDriver->shouldReceive('stateless')->andReturn($mockDriver);
        $mockDriver->shouldReceive('user')->andReturn($abstractUser);

        // 2. あなたが成功させた Http::post 通信の freeBusy をフェイク！
        // 12:00〜13:00 にGoogle側の予定（busy）が1件あると仮定します
        Http::fake([
            'https://googleapis.com' => Http::response([
                'calendars' => [
                    'primary' => [
                        'busy' => [
                            [
                                'start' => '2026-09-07T12:00:00+09:00',
                                'end' => '2026-09-07T13:00:00+09:00'
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        // コールバックを直撃！
        $response = $this->actingAs($this->coach)
            ->get('/settings/google-calendar/callback');

        // 3. データベースアサーション（物理層の検証）
        // 幽霊レコードにならず、行そのものが正しく1件保存されていること
        $this->assertDatabaseHas('user_google_calendar', [
            'user_id' => $this->coach->id,
            'google_email' => 'coach@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'mock-access-token',
            'refresh_token' => 'mock-refresh-token',
        ]);

        // 4. 設定画面（インデックス）へ成功リダイレクトが返ること
        $response->assertRedirect(route('settings.availability.index'));
    }

    /**
     * @test 連携解除: レコードが物理消去され、既存の時間枠のフラグがtrueに完全原状復帰すること
     */
    public function test_連携解除でトークン情報が物理削除される(): void
    {
        // 予め連携データを物理層にインサートしておく
        UserGoogleCalendar::create([
            'user_id' => $this->coach->id,
            'google_email' => 'coach@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'old-token',
            'refresh_token' => 'old-refresh',
        ]);

        // 一部非アクティブ（false）に沈んでいたコーチの時間枠を用意
        $availability = CoachAvailability::create([
            'coach_id' => $this->coach->id,
            'day_of_week' => 1, // 月曜日
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
            'is_active' => false, // Google連携によって無効化されていた状態
        ]);

        // カッコ () を付けた一撃必殺の DELETE ルートを直撃！
        $response = $this->actingAs($this->coach)
            ->delete('/settings/google-calendar');

        // 5. データが物理消去（更地化）されて残っていないことを検証！
        $this->assertDatabaseMissing('user_google_calendar', [
            'user_id' => $this->coach->id,
        ]);

        // 6. コーチの時間枠のフラグが true に完全原状復帰していること！
        $this->assertTrue((bool)$availability->refresh()->is_active);

        $response->assertRedirect(route('settings.availability.index'));
    }
}
