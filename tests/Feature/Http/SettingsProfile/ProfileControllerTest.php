<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $coach;

    private string $rawPassword;

    /**
     * 各テストの初期状態セットアップ（本物のマイグレーション・Enum仕様に100%完全同期）
     */
    protected function setUp(): void
    {
        parent::setUp();

        $invitedStatus = UserStatus::Invited->value ?? 'invited';
        $this->rawPassword = 'Password1234!';

        // 1. テスト用の受講生（Student）を生成
        $this->student = User::factory()->create([
            'role' => UserRole::Student->value ?? 'student',
            'status' => $invitedStatus,
            'password' => Hash::make($this->rawPassword),
            'bio' => '初期の自己紹介文です。',
        ]);

        // 2. テスト用のコーチ（Coach）を生成
        $this->coach = User::factory()->create([
            'role' => UserRole::Coach->value ?? 'coach',
            'status' => $invitedStatus,
            'password' => Hash::make($this->rawPassword),
        ]);
    }

    /**
     * ① 設定画面表示（edit）の認証 ＆ タブパラメータマウント網羅テスト
     */
    public function test_ユーザーは自分のプロフィール設定画面をタブパラメータ付きで正常に表示できること(): void
    {
        $response = $this->actingAs($this->student)->get(route('settings.profile.edit', ['tab' => 'profile']));

        $response->assertStatus(200);
        // 本物のマイグレーションカラムである「bio」に紐づく初期値が画面に実在することを確認
        $response->assertSee('初期の自己紹介文です。');
        $response->assertViewHas('tab', 'profile');
    }

    /**
     * ② プロフィール更新（update: PATCH）＆ 本物カラム（bio）への完全保存テスト
     */
    public function test_ユーザーは自分の氏名および自己紹介を本物のbioカラムに対して正常に更新できること(): void
    {
        $patchData = [
            'name' => '新しき受講生氏名',
            'introduction' => '新しく書き換えた最高の自己紹介文（bio）です。',
        ];

        $response = $this->actingAs($this->student)
            ->from(route('settings.profile.edit'))
            ->patch(route('settings.profile.update'), $patchData);

        $response->assertRedirect(route('settings.profile.edit', ['tab' => 'profile']));
        $response->assertSessionHas('success');

        // 本物のマイグレーション仕様である「bio」カラムに美しくインサートされていることを厳格に証明！
        $this->assertDatabaseHas('users', [
            'id' => $this->student->id,
            'name' => '新しき受講生氏名',
            'bio' => '新しく書き換えた最高の自己紹介文（bio）です。',
        ]);
    }

    /**
     * ③ パスワード変更（updatePassword: PUT）の現確認 ＆ 正常ハッシュ化保存テスト
     */
    public function test_ユーザーは現在のパスワードを正しく入力したうえで新しいパスワードに正常に変更できること(): void
    {
        $putData = [
            'current_password' => $this->rawPassword,
            'password' => 'NewSecurePassword5678!',
            'password_confirmation' => 'NewSecurePassword5678!',
        ];

        $response = $this->actingAs($this->student)
            ->from(route('settings.profile.edit'))
            ->put(route('settings.password.update'), $putData);

        $response->assertRedirect(route('settings.profile.edit', ['tab' => 'password']));
        $response->assertSessionHas('success');

        // データベース側のパスワードが安全にハッシュ化され、新しいものに切り替わっていることを証明！
        $this->assertTrue(Hash::check('NewSecurePassword5678!', $this->student->refresh()->password));
    }

    /**
     * ④ パスワード変更時のバリデーション（不一致・境界値）境界テスト
     */
    public function test_現在のパスワードが一致しないか新しいパスワードが最低文字数を満たさない場合はエラーになること(): void
    {
        $invalidData = [
            'current_password' => 'WrongCurrentPassword!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ];

        $response = $this->actingAs($this->student)
            ->from(route('settings.profile.edit'))
            ->put(route('settings.password.update'), $invalidData);

        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHasErrors(['current_password', 'password']);
    }

    /**
     * ⑤ アバター画像のアップロード（POST）＆ 本物カラム（avatar_url）格納テスト
     */
    public function test_ユーザーはアバター画像をアップロードし本物のavatar_urlカラムへパスを格納できること(): void
    {
        Storage::fake('public');

        $dummyAvatar = UploadedFile::fake()->image('my_avatar.jpg')->size(500);

        $response = $this->actingAs($this->student)
            ->post(route('settings.avatar.store'), [
                'avatar' => $dummyAvatar,
            ]);

        $response->assertRedirect(route('settings.profile.edit', ['tab' => 'avatar']));
        $response->assertSessionHas('success');

        // 本物のマイグレーションカラム「avatar_url」に、格納パス（avatars/...）が確実に実在することを検証！
        $this->assertNotNull($this->student->refresh()->avatar_url);
        Storage::disk('public')->assertExists($this->student->avatar_url);
    }

    /**
     * ⑥ アバター画像の削除（DELETE）＆ 初期アバター表示への復元テスト
     */
    public function test_ユーザーは登録済みのアバター画像を削除して初期アバター状態へ安全に復元できること(): void
    {
        Storage::fake('public');

        $uploadedPath = 'avatars/existing_avatar.png';
        Storage::disk('public')->put($uploadedPath, 'dummy-content');

        $this->student->update(['avatar_url' => $uploadedPath]);

        $response = $this->actingAs($this->student)->delete(route('settings.avatar.destroy'));

        $response->assertRedirect(route('settings.profile.edit', ['tab' => 'avatar']));
        $response->assertSessionHas('danger');

        $this->assertNull($this->student->refresh()->avatar_url);
        Storage::disk('public')->assertMissing($uploadedPath);
    }
}
