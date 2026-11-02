<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\AiChat;

use App\Actions\AiChat\StoreConversationAction;
use App\Enums\UserRole;
use App\Models\AiChatConversation;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoreConversationActionTest extends TestCase
{
    use RefreshDatabase;

    private StoreConversationAction $action;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new StoreConversationAction;
        $this->student = User::factory()->create(['role' => UserRole::Student]);
    }

    /**
     * @test
     */
    public function test_新規の会話スレッドが正常に永続化されること(): void
    {
        $result = $this->action->execute($this->student, [
            'section_id' => null,
        ]);

        $this->assertFalse($result['is_merged']);
        $this->assertInstanceOf(AiChatConversation::class, $result['conversation']);
        $this->assertDatabaseHas('ai_chat_conversations', [
            'user_id' => $this->student->id,
            'title' => '新しい学習相談',
        ]);
    }

    public function test_auto_title_option_is_persisted_when_creating_a_conversation(): void
    {
        $result = $this->action->execute($this->student, [
            'section_id' => null,
            'auto_title_enabled' => false,
        ]);

        $this->assertFalse($result['conversation']->auto_title_enabled);
    }

    /**
     * @test
     */
    public function test_同じ教材のセクション_i_dが指定された場合は新規作成されず既存スレッドに自動合流すること(): void
    {
        $section = Section::factory()->create();

        $firstResult = $this->action->execute($this->student, [
            'section_id' => $section->id,
        ]);

        $secondResult = $this->action->execute($this->student, [
            'section_id' => $section->id,
        ]);

        $this->assertTrue($secondResult['is_merged']);
        $this->assertEquals($firstResult['conversation']->id, $secondResult['conversation']->id);
        $this->assertEquals(1, AiChatConversation::where('user_id', $this->student->id)->count());
    }
}
