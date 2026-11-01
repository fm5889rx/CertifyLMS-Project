<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Class ExternalApiGroupTest
 *
 * @group external
 */
final class ExternalApiGroupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * @test
     */
    public function test_未モックの外部リクエストが送信された場合は実通信へ行かずに確実に遮断されること(): void
    {
        $this->expectException(\RuntimeException::class);
        Http::get('https://stripe.com');
    }

    /**
     * @test
     * @group external
     */
    public function test_外部_a_p_i_に依存するテストをまとめて分離実行できること(): void
    {
        $this->assertTrue(true);
    }
}
