<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('title', 255);
            $table->boolean('auto_title_enabled')->default(true);
            $table->timestamp('last_message_at')->useCurrent();
            $table->timestamps();

            // 受講生 × 教材の会話の重複乱立を物理層で防御する複合ユニーク
            $table->unique(['user_id', 'section_id']);

            // 履歴の高速アクセスのためのインデックス
            $table->index(['user_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_conversations');
    }
};
