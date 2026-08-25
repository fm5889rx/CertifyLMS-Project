<?php

declare(strict_types=1);

namespace Tests\Feature\Http\UserManagement;

use App\Models\User;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\UseCases\User\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserManagementFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $activeStudent;
    private User $withdrawnStudent;

    /**
     * 各テストの初期状態セットアップ（型安全Enumオブジェクト徹底）
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgressStatus = UserStatus::InProgress;

        // 1. 通常一覧に出現すべき在籍中（InProgress）の受講生を生成
        $this->activeStudent = User::create([
            'id'                => (string) Str::ulid(),
            'name'              => '在籍受講生A',
            'email'             => 'active-test@example.com',
            'password'          => bcrypt('password'),
            'role'              => UserRole::Student,
            'status'            => $inProgressStatus,
            'email_verified_at' => now(),
        ]);

        // 2. 通常一覧から除外されるべき退会済（Withdrawn）の受講生を生成し、SoftDelete（論理削除）を適用
        $this->withdrawnStudent = User::create([
            'id'                => (string) Str::ulid(),
            'name'              => '退会受講生B',
            'email'             => 'withdrawn-test@example.com',
            'password'          => bcrypt('password'),
            'role'              => UserRole::Student,
            'status'            => UserStatus::Withdrawn,
            'email_verified_at' => now(),
        ]);
        $this->withdrawnStudent->delete(); // soft delete 状態
    }

    /**
     * ① 状態フィルタなし（または通常時）の除外テスト
     */
    public function test_状態フィルタを指定しない場合は退会済みユーザーが一覧から厳格に除外されること(): void
    {
        $action = resolve(IndexAction::class);

        // $status 引数に null（フィルタなし）を渡してクエリを実行
        $result = $action(
            keyword: null,
            role: null,
            status: null
        );

        // 通常一覧の件数は「1件」であり、退会者は絶対に混入していないことを検証
        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->activeStudent->id, $result->first()->id);
    }

    /**
     * ② 状態フィルタ「退会済」指定時の露出テスト
     */
    public function test_状態フィルタに退会済を指定したときのみ論理削除された退会済みユーザーが一覧に露出すること(): void
    {
        $action = resolve(IndexAction::class);

        // $status 引数に UserStatus::Withdrawn を明示して実行
        $result = $action(
            keyword: null,
            role: null,
            status: UserStatus::Withdrawn
        );

        // 退会フィルタ時は、隠れていた退会受講生が表示されることを検証
        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->withdrawnStudent->id, $result->first()->id);
    }
}
