<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Stripe;

use App\Actions\Stripe\ProcessStripeWebhookAction;
use App\Enums\PaymentStatus;
use App\Models\User;
use App\Models\MeetingPack;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Event;
use Tests\TestCase;

final class ProcessStripeWebhookActionTest extends TestCase
{
    use RefreshDatabase;

    private ProcessStripeWebhookAction $action;
    private User $student;
    private MeetingPack $pack;
    private Payment $pendingPayment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ProcessStripeWebhookAction();
        $this->student = User::factory()->create();
        $this->pack = MeetingPack::factory()->create(['price' => 5000, 'meeting_count' => 3]);
        
        // 決済のライフサイクルに基づき、保留状態の決済レコードを先在させる
        $this->pendingPayment = Payment::create([
            'user_id' => $this->student->id,
            'meeting_pack_id' => $this->pack->id,
            'amount' => $this->pack->price,
            'quantity' => $this->pack->meeting_count,
            'status' => PaymentStatus::Pending,
            'stripe_checkout_session_id' => 'mock_session_123',
        ]);
    }

    /**
     * @test
     */
    public function test_決済完了通知を受信した際に保留レコードが成功状態に遷移し面談残数が同一トランザクション内で加算されること(): void
    {
        $event = new Event();
        $event->type = 'checkout.session.completed';
        $event->data = (object)[
            'object' => (object)[
                'id' => 'mock_session_123',
                'payment_intent' => 'pi_mock_999',
                'metadata' => (object)[
                    'user_id' => $this->student->id,
                    'meeting_pack_id' => $this->pack->id,
                ]
            ]
        ];

        $result = $this->action->execute($event);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(PaymentStatus::Completed, $this->pendingPayment->refresh()->status);

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $this->student->id,
            'amount' => 3,
            'related_payment_id' => $this->pendingPayment->id,
        ]);
    }

    /**
     * @test
     */
    public function test_決済失敗通知を受信した際に保留レコードが失敗状態に遷移し残数加算は行われないこと(): void
    {
        $event = new Event();
        $event->type = 'checkout.session.expired';
        $event->data = (object)[
            'object' => (object)[
                'id' => 'mock_session_123',
            ]
        ];

        $result = $this->action->execute($event);

        $this->assertEquals('failed_processed', $result['status']);
        $this->assertEquals(PaymentStatus::Failed, $this->pendingPayment->refresh()->status);

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'user_id' => $this->student->id,
        ]);
    }
}
