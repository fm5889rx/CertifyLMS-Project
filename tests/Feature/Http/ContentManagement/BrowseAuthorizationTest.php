<?php

declare(strict_types=1);

namespace Tests\Feature\Http\ContentManagement;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrowseAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Certification $registeredCert;

    private Certification $unregisteredCert;

    private Part $registeredPart;

    private Part $unregisteredPart;

    private Chapter $unregisteredChapter;

    private Section $unregisteredSection;

    /**
     * 各テストの初期状態セットアップ（受講登録あり資格と、完全未登録資格の対比世界線を精巧にマウント）
     */
    protected function setUp(): void
    {
        parent::setUp();

        $inProgressStatus = UserStatus::InProgress;
        $this->student = User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus]);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => $inProgressStatus]);

        // 1. 受講登録している「資格X」およびその配下の教材
        $this->registeredCert = Certification::factory()->create([
            'status' => CertificationStatus::Published,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
        $this->registeredPart = Part::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $this->registeredCert->id,
            'title' => '登録済Part',
            'order' => 1,
            'status' => ContentStatus::Published->value,
        ]);
        // 受講登録（Enrollment）をマウント
        Enrollment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $this->student->id,
            'certification_id' => $this->registeredCert->id,
            'status' => EnrollmentStatus::Learning->value,
        ]);

        // 2. 受講登録していない「資格Y」およびその配下の3階層教材
        $this->unregisteredCert = Certification::factory()->create([
            'status' => CertificationStatus::Published,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
        $this->unregisteredPart = Part::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $this->unregisteredCert->id,
            'title' => '未登録Part',
            'order' => 1,
            'status' => ContentStatus::Published->value,
        ]);
        $this->unregisteredChapter = Chapter::create([
            'id' => (string) Str::ulid(),
            'part_id' => $this->unregisteredPart->id,
            'title' => '未登録Chapter',
            'order' => 1,
            'status' => ContentStatus::Published->value,
        ]);
        $this->unregisteredSection = Section::create([
            'id' => (string) Str::ulid(),
            'chapter_id' => $this->unregisteredChapter->id,
            'title' => '未登録Section',
            'body' => '極秘教材本文',
            'order' => 1,
            'status' => ContentStatus::Published->value,
        ]);
    }

    /**
     * ① 登録済み資格の教材への正常アクセス検証 (対比用)
     */
    public function test_受講登録している資格の教材詳細画面は正常に200_o_kで閲覧できること(): void
    {
        $response = $this->actingAs($this->student)
            ->get(route('learning.parts.show', $this->registeredPart));

        $response->assertStatus(200);
    }

    /**
     * ② 未登録資格の Part 直リンク拒否検証
     */
    public function test_受講登録していない資格の_part詳細画面へ直リンクでアクセスした場合は厳格に403_forbiddenで遮断されること(): void
    {
        $response = $this->actingAs($this->student)
            ->get(route('learning.parts.show', $this->unregisteredPart));

        $response->assertStatus(403);
    }

    /**
     * ③ 未登録資格の Chapter 直リンク拒否検証
     */
    public function test_受講登録していない資格の_chapter詳細画面へ直リンクでアクセスした場合は厳格に403_forbiddenで遮断されること(): void
    {
        $response = $this->actingAs($this->student)
            ->get(route('learning.chapters.show', $this->unregisteredChapter));

        $response->assertStatus(403);
    }

    /**
     * ④ 未登録資格の Section 直リンク拒否検証
     */
    public function test_受講登録していない資格の_section詳細画面へ直リンクでアクセスした場合は厳格に403_forbiddenで遮断されること(): void
    {
        $response = $this->actingAs($this->student)
            ->get(route('learning.sections.show', $this->unregisteredSection));

        $response->assertStatus(403);
    }
}
