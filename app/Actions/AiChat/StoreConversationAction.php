<?php

declare(strict_types=1);

namespace App\Actions\AiChat;

use App\Models\AiChatConversation;
use App\Models\Section;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class StoreConversationAction
{
    /**
     * Creates a conversation or resumes the existing conversation for a section.
     *
     * @param array<string, mixed> $data
     *
     * @return array{conversation: AiChatConversation, is_merged: bool}
     */
    public function execute(User $user, array $data): array
    {
        $sectionId = isset($data['section_id']) ? (string) $data['section_id'] : null;

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

        $section = ! empty($sectionId) ? Section::find($sectionId) : null;
        $title = $section ? '【教材相談】'.$section->title : '新しい学習相談';

        $conversation = AiChatConversation::create([
            'user_id' => $user->id,
            'section_id' => $sectionId,
            'title' => Str::limit($title, 50, ''),
            'auto_title_enabled' => (bool) ($data['auto_title_enabled'] ?? true),
            'last_message_at' => Carbon::now(),
        ]);

        return [
            'conversation' => $conversation,
            'is_merged' => false,
        ];
    }
}
