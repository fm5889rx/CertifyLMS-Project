<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notifications;

use App\Models\User;
use App\Models\Enrollment;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\AnnouncementTargetType;
use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $studentA;
    private User $studentB;
    private User $coach;
    private User $adminUser;
    private Certification $certification;
    private Enrollment $enrollmentA;

    /**
     * 各テストの初期状態セットアップ（すべての必須制約を本物の型仕様でマウント）
     */
    protected function setUp(): void
    {
        parent::setUp();

        // すべてのロールデータを準備
        $inProgressStatus = UserStatus::InProgress;

        $this->studentA  = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus]);
        $this->studentB  = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus]);
        $this->coach     = User::factory()->create(['role' => UserRole::Coach, 'status' => $inProgressStatus]);
        $this->adminUser = User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus]);

        $category = CertificationCategory::create([
            'id'   => (string) Str::ulid(),
            'slug' => 'test-announcement-slug-' . Str::random(5),
            'name' => 'テストお知らせカテゴリ',
        ]);

        $this->certification = Certification::create([
            'id'                  => (string) Str::ulid(),
            'category_id'         => $category->id,
            'name'                => 'テスト対象資格マスター',
            'difficulty'          => CertificationDifficulty::Intermediate,
            'status'              => CertificationStatus::Published,
            'created_by_user_id'  => $this->adminUser->id,
            'updated_by_user_id'  => $this->adminUser->id,
        ]);

        $this->enrollmentA = Enrollment::create([
            'id'               => (string) Str::ulid(),
            'user_id'          => $this->studentA->id,
            'certification_id' => $this->certification->id,
            'status'           => EnrollmentStatus::Learning,
            'current_term'     => TermType::BasicLearning,
            'exam_date'        => now()->addMonths(3)->toDateString(),
        ]);
    }

    /**
     * ① 配信対象タイプA：「全受講生」への一斉配信テスト
     */
    public function test_管理者はお知らせを全受講生に向けて一斉配信でき配信履歴と通知が自動生成されること(): void
    {
        // 配信データを準備
        $postData = [
            'title'       => 'サーバーメンテナンスのお知らせ',
            'body'        => '今週末の日曜日の午前2時から4時までシステムメンテナンスを行います。',
            'target_type' => AnnouncementTargetType::AllStudents->value, // 💡本物のバリュー 'all'
        ];

        // 実行：配信データをPOSTリクエスト
        $response = $this->actingAs($this->adminUser)
            ->post(route('admin.announcements.store'), $postData);

        // お知らせ配信一覧画面にリダイレクトされているか検証
        $response->assertRedirect(route('admin.announcements.index'));

        // テーブルに新規追加されているか検証
        $this->assertDatabaseHas('announcements', [
            'title'              => 'サーバーメンテナンスのお知らせ',
            'target_type'        => AnnouncementTargetType::AllStudents->value,
            'dispatched_count'   => 2,
            'created_by_user_id' => $this->adminUser->id,
        ]);

        // 通知基盤へのリレー連動を検証
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->studentA->id,
            'type'          => 'App\Notifications\AdminAnnouncementNotification',
        ]);
    }

    /**
     * ② 配信対象タイプB：「資格指定」でのセグメント配信テスト
     */
    public function test_管理者はお知らせを指定した資格に登録中の受講生だけに絞り込んでセグメント配信できること(): void
    {
        // 配信データを準備
        $postData = [
            'title'                   => '教材アップデートの告知',
            'body'                    => '指定資格の新しい模擬試験問題を追加しました。確認してください。',
            'target_type'             => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $this->certification->id,
        ];

        // 実行：配信データをPOSTリクエスト
        $response = $this->actingAs($this->adminUser)->post(route('admin.announcements.store'), $postData);

        // お知らせ配信一覧画面にリダイレクトされているか検証
        $response->assertRedirect(route('admin.announcements.index'));

        // 配信先が資格指定かどうか検証
        $this->assertDatabaseHas('announcements', [
            'title'            => '教材アップデートの告知',
            'target_type'      => AnnouncementTargetType::Certification->value,
            'dispatched_count' => 1,
        ]);

        // 配信先が受講生Aに絞り込まれているか検証
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->studentA->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->studentB->id]);
    }

    /**
     * ③ 配信対象タイプC：「ユーザー指定」での個別配信テスト
     */
    public function test_管理者はお知らせを指定した単一の受講生のみをターゲットにして個別配信できること(): void
    {
        // 配信データを準備
        $postData = [
            'title'          => '個別フォローアップ連絡',
            'body'           => '最近の学習進捗について個別に確認したい事項があります。',
            'target_type'    => AnnouncementTargetType::User->value,
            'target_user_id' => $this->studentA->id,
        ];

        // 実行：配信データをPOSTリクエスト
        $response = $this->actingAs($this->adminUser)->post(route('admin.announcements.store'), $postData);

        // お知らせ配信一覧画面にリダイレクトされているか検証
        $response->assertRedirect(route('admin.announcements.index'));

        // 配信先がユーザ指定になっているか検証
        $this->assertDatabaseHas('announcements', [
            'target_type'      => AnnouncementTargetType::User->value,
            'dispatched_count' => 1,
        ]);

        // 配信先が受講生Aに絞り込まれているか検証
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->studentA->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->studentB->id]);
    }

    /**
     * ④ 履歴監査機能（index / show）の閲覧テスト
     */
    public function test_管理者は配信済みお知らせの一覧および詳細画面を正常に確認できること(): void
    {
        // 配信データを作成
        $announcement = Announcement::create([
            'id'                 => (string) Str::ulid(),
            'title'              => '過去の配信履歴タイトル',
            'body'               => '過去の本文内容です。',
            'target_type'        => AnnouncementTargetType::AllStudents, // 💡Enumキャスト
            'dispatched_count'   => 5,
            'created_by_user_id' => $this->adminUser->id,
            'dispatched_at'      => now(),
        ]);

        // 配信一覧取得のGETリクエスト
        $response = $this->actingAs($this->adminUser)->get(route('admin.announcements.index'));
        // 正常終了しているか検証
        $response->assertStatus(200);
        // 配信一覧画面が表示されているか検証
        $response->assertSee('過去の配信履歴タイトル');

        // 配信内容詳細取得のGETリクエスト
        $response = $this->actingAs($this->adminUser)->get(route('admin.announcements.show', $announcement->id));
        // 正常終了しているか検証
        $response->assertStatus(200);
        // 配信詳細画面が表示されているか検証
        $response->assertSee('過去の本文内容です。');
    }

    /**
     * ⑤ セキュリティ認可制御（管理者以外の全アクセス403直撃遮断）テスト
     */
    public function test_コーチや受講生がお知らせ配信画面への侵入や配信操作を試みた場合は一律で403認可拒否されること(): void
    {
        // コーチが新規廃止画面を表示するGETリクエスト
        $response = $this->actingAs($this->coach)->get(route('admin.announcements.create'));
        // 権限エラー403が返ってくるか検証
        $response->assertStatus(403);

        // 受講生が配信しようとするPOSTリクエスト
        $response = $this->actingAs($this->studentA)->post(route('admin.announcements.store'), [
            'title'       => '受講生が勝手に送るタイトル',
            'body'        => '本文',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);
        // 権限エラー403が返ってくるか検証
        $response->assertStatus(403);
    }
}
