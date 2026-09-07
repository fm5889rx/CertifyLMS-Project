<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google Calendar 連携用テーブル（S-A-01追加）
     */
    public function up(): void
    {
        schema::create('user_google_calendar', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->string('google_email', 255)->nullable();
            $table->string('calendar_id', 255);
            $table->timestamp('connected_at')->nullable();
            $table->string('access_token', 255)->nullable();
            $table->string('refresh_token', 255)->nullable();
            $table->timestamps();

            $table->unique('user_id', 'uk_user_google_calendar_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
