<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meeting_reminder_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('meeting_id');
            $table->foreign('meeting_id')
                ->references('id')
                ->on('meetings')
                ->cascadeOnDelete();
            $table->string('window', 30);
            $table->timestamps();

            $table->unique(['meeting_id', 'window'], 'uk_meeting_reminder_window');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_reminder_logs');
    }
};
