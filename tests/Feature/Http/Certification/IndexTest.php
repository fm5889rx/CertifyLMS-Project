<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certification;

use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\CertificationCoachAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * admin / coach 用の資格マスタ一覧フィルタ検証。(B-B-11 修正版)
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_certification_list(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.certifications.index'));

        $response->assertOk();
        $response->assertViewIs('certification.management.index');
        $response->assertViewHas('certifications');
    }

    public function test_coach_sees_only_assigned_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $assigned = Certification::factory()->published()->create(['name' => 'My Assigned Cert']);
        $other = Certification::factory()->published()->create(['name' => 'Unassigned Cert']);

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $assigned->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($coach)->get(route('admin.certifications.index'));

        $response->assertOk();
        $response->assertSee('My Assigned Cert');
        $response->assertDontSee('Unassigned Cert');
    }

    public function test_student_cannot_access_admin_certifications_index(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.certifications.index'))
            ->assertForbidden();
    }

    public function test_keyword_filter_matches_name_only(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->create(['name' => 'TOEIC Listening']);
        Certification::factory()->published()->create(['name' => 'PMP Certification']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['keyword' => 'TOEIC']));

        $response->assertOk();
        $response->assertSee('TOEIC Listening');
        $response->assertDontSee('PMP Certification');
    }

    /**
     * 状態フィルタ「公開中」の絞り込み検証
     * ユースケース側が動的バインドになったため、元々100%Redエラーだったこのテストが
     * 完璧にALL GREENで突破できるようになります！
     */
    public function test_status_filter_returns_only_matching_status(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createTestDataForStatusFilter();

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['status' => 'published']));

        $response->assertOk();
        $response->assertSee('Published One');
        $response->assertDontSee('Draft One');
        $response->assertDontSee('Archived One');
    }

    /**
     * 【新規追加・境界値拡張】：状態フィルタ「下書き」の絞り込み検証
     * 「下書き」を指定した際にも、公開中やアーカイブが完全に除外される対称性を検証
     */
    public function test_status_filter_returns_only_draft_status_when_draft_selected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createTestDataForStatusFilter();

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['status' => 'draft']));

        $response->assertOk();
        $response->assertSee('Draft One');
        $response->assertDontSee('Published One');
        $response->assertDontSee('Archived One');
    }

    /**
     * 新規追加・境界値拡張】：状態フィルタ「アーカイブ」の絞り込み検証
     */
    public function test_status_filter_returns_only_archived_status_when_archived_selected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createTestDataForStatusFilter();

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['status' => 'archived']));

        $response->assertOk();
        $response->assertSee('Archived One');
        $response->assertDontSee('Published One');
        $response->assertDontSee('Draft One');
    }

    public function test_category_filter(): void
    {
        $admin = User::factory()->admin()->create();
        $category = CertificationCategory::factory()->create(['name' => 'Tech Cert']);
        Certification::factory()->published()->create(['name' => 'Match', 'category_id' => $category->id]);
        Certification::factory()->published()->create(['name' => 'OtherCat']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['category_id' => $category->id]));

        $response->assertOk();
        $response->assertSee('Match');
        $response->assertDontSee('OtherCat');
    }

    public function test_paginates_20_per_page(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->count(22)->create();

        $response = $this->actingAs($admin)->get(route('admin.certifications.index'));

        $response->assertOk();
        $certs = $response->viewData('certifications');
        $this->assertSame(20, $certs->perPage());
        $this->assertSame(22, $certs->total());
    }

    /**
     * B-B-11にて追加
     * ステータスフィルタテスト用のファクトリデータヘルパー
     */
    private function createTestDataForStatusFilter(): void
    {
        Certification::factory()->draft()->create(['name' => 'Draft One']);
        Certification::factory()->published()->create(['name' => 'Published One']);
        Certification::factory()->archived()->create(['name' => 'Archived One']);
    }
}
