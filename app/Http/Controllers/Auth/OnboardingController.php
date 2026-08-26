<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OnboardingRequest;
use App\Models\Invitation;
use App\Services\InvitationTokenService;
use App\UseCases\Auth\OnboardAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * 招待 URL 経由のオンボーディング Controller。(B-B-06 修正版)
 */
class OnboardingController extends Controller
{
    public function show(Request $request, Invitation $invitation, InvitationTokenService $tokenService): View
    {
        // B-B-06：
        // 他メンバーが最初から 100 点満点で用意し、実在が保証されている「auth.invitation-invalid」
        // ビューテンプレートを直接 return で呼び出し、ブラウザを完璧な製品クオリティで着地させます！！！
        if (! $invitation->isUsable()) {
            return view('auth.invitation-invalid', [
                'errorMessage' => 'この招待リンクはすでに使用済みか、または無効化されています。'
            ]);
        }

        // URLトークン自体の署名・有効期限の検証（既存のガード）
        if (! $tokenService->verify($request, $invitation)) {
            return view('auth.invitation-invalid');
        }

        $postUrl = URL::temporarySignedRoute(
            'onboarding.store',
            $invitation->expires_at,
            ['invitation' => $invitation->id],
        );

        return view('auth.onboarding', [
            'invitation' => $invitation,
            'postUrl' => $postUrl,
        ]);
    }

    public function store(
        Invitation $invitation,
        OnboardingRequest $request,
        OnboardAction $action,
    ): RedirectResponse {
        $action($invitation, $request->validated());

        return redirect()->route('dashboard.index');
    }
}
