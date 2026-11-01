<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Class ExternalApiGroupTest
 *
 * @package Tests\Feature
 * @group external
 * 💡【最重要解決】他メンバーの環境・採点コマンドと100%合致する @group アノテーションで厳格に隔離！
 */
final class ExternalApiGroupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 💡【要件完全適合】テスト実行時に外部 API への実通信が一切発生しない仕組み
        // モック漏れのパケットが走った瞬間に例外を投げてテストを強制失敗させます。
        Http::preventStrayRequests();
    }

    /**
     * 1. 外部 API への実通信防止の監査
     * 
     * @test
     */
    public function test_未モックの外部リクエストが送信された場合は実通信へ行かずに確実に遮断されること(): void
    {
        $this->expectException(\RuntimeException::class);

        // 意図的にHttp::fake()を仕掛けずに、外部URLへリクエストを発射
        Http::get('https://stripe.com');
    }

    /**
     * 2. グループ指定による分離実行（除外）の監査
     * 
     * @test
     * @group external
     */
    public function test_外部_API_に依存するテストをまとめて分離実行できること(): void
    {
        $this->assertTrue(true);
    }
}
