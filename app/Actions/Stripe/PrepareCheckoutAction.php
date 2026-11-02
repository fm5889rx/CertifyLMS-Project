<?php

declare(strict_types=1);

namespace App\Actions\Stripe;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Stripe\Checkout\Session;
use Stripe\Stripe;

final class PrepareCheckoutAction
{
    public function __construct()
    {
        Stripe::setApiKey((string) config('services.stripe.secret', ''));
    }

    /**
     * Creates a pending payment and returns its Stripe Checkout URL.
     *
     * @param User $user The user purchasing the meeting pack.
     * @param MeetingPack $pack The published meeting pack selected for purchase.
     *
     * @return string The Stripe-hosted checkout URL.
     */
    public function execute(User $user, MeetingPack $pack): string
    {
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'jpy',
                    'product_data' => [
                        'name' => $pack->name,
                        'description' => $pack->description ?? "追加面談回数: {$pack->meeting_count}回分",
                    ],
                    'unit_amount' => $pack->price,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('meeting-quota.checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('meeting-quota.checkout.select'),
            'metadata' => [
                'user_id' => $user->id,
                'meeting_pack_id' => $pack->id,
            ],
        ]);

        Payment::create([
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'amount' => $pack->price,
            'quantity' => $pack->meeting_count,
            'status' => PaymentStatus::Pending,
            'stripe_checkout_session_id' => $session->id,
        ]);

        return $session->url;
    }
}
