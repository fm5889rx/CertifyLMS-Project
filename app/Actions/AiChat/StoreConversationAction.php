<?php

declare(strict_types=1);

namespace App\Actions\AiChat;

use App\Models\User;
use App\Models\Section;
use App\Models\AiChatConversation;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class StoreConversationAction
{
    /**
     * AI相談の新しい会話（スレッド）を作成、または既存教材スレッドへの自動合流を制御する
     * 
     * @param User $user
     * @param array<string, mixed> $data
     * @return array{conversation: AiChatConversation, is_merged: bool}
     */
    public function execute(User $user, array $data): array
    {
        $sectionId = isset($data['section_id']) ? (string) $data['section_id'] : null;

        // 【受け入れ条件】教材セクションのウィジェットから相談を始めた場合は、その Section の既存会話があれば再開（合流）
        if (! empty($sectionId)) {
            $existing = AiChatConversation::where('user_id', $user->id)
                ->where('section_id', $sectionId)
                ->first();

            if ($existing) {
                return [
                    'conversation' => $existing,
                    'is_merged' => true,
                ];
            }
        }

        // 新規作成時の暫定タイトルの組み立て（教材からの場合は教材タイトルを自動付与）
        $section = ! empty($sectionId) ? Section::find($sectionId) : null;
        $title = $section ? '【教材相談】'.$section->title : '新しい学習相談';

        // 完璧な新規会話スレッドをEloquent層から永続化
        $conversation = AiChatConversation::create([
            'user_id' => $user->id,
            'section_id' => $sectionId,
            'title' => Str::limit($title, 50, ''),
            'auto_title_enabled' => true,
            'last_message_at' => Carbon::now(),
        ]);

        return [
            'conversation' => $conversation,
            'is_merged' => false,
        ];
    }
}
