<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ai_chat_conversation_id')->constrained('ai_chat_conversations')->cascadeOnDelete();
            $table->string('role');
            $table->string('status');
            $table->longtext('content')->nullable();
            $table->text('error_detail')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('model_name')->nullable();                   // Gemini 使用モデル
            $table->timestamps();

            $table->index(['ai_chat_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
