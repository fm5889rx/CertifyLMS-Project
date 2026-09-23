<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingQuotaTransactionType;
use App\Http\Requests\MeetingQuota\HistoryIndexRequest;
use App\Models\MeetingQuotaTransaction;
use App\Services\MeetingQuotaService;
use Illuminate\View\View;

/**
 * 受講生本人の面談回数履歴を表示する Controller。
 * type フィルタ + paginate で表示し、残面談回数も合わせて表示する。
 */
class MeetingQuotaHistoryController extends Controller
{
    public function index(HistoryIndexRequest $request, MeetingQuotaService $service): View
    {
        $user = $request->user();
        $validated = $request->validated();
        $type = isset($validated['type'])
            ? MeetingQuotaTransactionType::from($validated['type'])
            : null;

        $transactions = $service->history($user, $type);

        // S-A-03で追加
        // これまでの取引履歴の amount のプラス・マイナスをすべて足し算（sum）して
        // 現在の「本物の真の残数」を弾き出す
        $remaining = (int) MeetingQuotaTransaction::where('user_id', $user->id)->sum('amount');

        return view('meeting-quota.history', [
            'transactions' => $transactions,
            //            'remaining' => $service->remaining($user),
            'remaining' => $remaining,              // S-A-03で変更
            'type' => $validated['type'] ?? '',
        ]);
    }
}
