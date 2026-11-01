<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\AiChat;

use App\Actions\AiChat\StoreConversationAction;
use App\Models\Section;
use App\Models\User;
use App\Models\AiChatConversation;
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
        $this->action = new StoreConversationAction();
        $this->student = User::factory()->create(['role' => \App\Enums\UserRole::Student]);
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

    /**
     * @test
     */
    public function test_同じ教材のセクションIDが指定された場合は新規作成されず既存スレッドに自動合流すること(): void
    {
        $section = Section::factory()->create();

        $firstResult = $this->action->execute($this->student, [
            'section_id' => $section->id,
        ]);

        $secondResult = $this->action->execute($this->student, [
            'section_id' => $section->id,
        ]);

        $this->assertTrue($secondResult['is_merged']);
        // 💡 【修正】 undefined method エラーの元になっていた不要なコピペダミー1行を完全消去！
        $this->assertEquals($firstResult['conversation']->id, $secondResult['conversation']->id);
        $this->assertEquals(1, AiChatConversation::where('user_id', $this->student->id)->count());
    }
}
