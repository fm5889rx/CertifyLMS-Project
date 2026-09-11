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
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('meeting_pack_id')->constrained('meeting_packs')->restrictOnDelete();

            // 購入時点のマスタの生の数値をここに「控え（スナップショット）」として保存
            $table->unsignedInteger('amount');        // 購入時点の決済価格（円）
            $table->unsignedSmallInteger('quantity'); // 購入時点で付与された面談回数
            $table->string('status', 30);         // 'completed', 'pending', 'failed' (Enum型ロック)

            // Stripe から発行される checkout_session_id を一意のユニークキーとして型ロック
            // MySQL 側で同じ ID の重複インサートを物理的に拒絶（冪等性ガード）する
            $table->string('stripe_checkout_session_id', 255)->unique();
            $table->string('stripe_payment_intent_id', 255)->nullable()->unique();

            $table->timestamps();
        });

        // 既存の履歴テーブルに対して、payments テーブルへの外部キー（FK）を裏から繋ぎ直す
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->foreign('related_payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropForeign(['related_payment_id']);
        });

        Schema::dropIfExists('payments');
    }
};
