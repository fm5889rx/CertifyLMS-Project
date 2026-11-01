<?php

declare(strict_types=1);

namespace App\Actions\Stripe;

use App\Enums\PaymentStatus;
use App\Models\User;
use App\Models\MeetingPack;
use App\Models\Payment;
use Stripe\Stripe;
use Stripe\Checkout\Session;

final class PrepareCheckoutAction
{
    public function __construct()
    {
        Stripe::setApiKey((string) config('services.stripe.secret', ''));
    }

    /**
     * 決済履歴を「保留」で先行作成し、Stripe Checkout URLを返却する
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

        // 💡【重要】自動テストが事前に存在をアサートする「保留レコード」をここで生成
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
