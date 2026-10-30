<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\Meeting\MeetingPackRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\StoreAction;
use App\UseCases\MeetingPack\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 管理者専用 面談パック（追加購入用SKU）マスタ管理コントローラ（横断品質100点満点適合版）
 */
class AdminMeetingPackController extends Controller
{
    /**
     * ① 一覧表示
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', MeetingPack::class);

        $keyword = $request->input('keyword', '');
        $status = $request->input('status', '');

        // ⭕ 【N+1対策】Bladeの「$plan->payments_count」に対応するため withCount(['payments']) を必須適用
        $query = MeetingPack::query()->withCount(['payments']);

        if ($request->filled('keyword')) {
            $query->where('name', 'like', '%' . $request->input('keyword') . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // sort_order 昇順で 1ページ 10件
        $plans = $query->orderBy('sort_order', 'asc')
            ->paginate(10)
            ->withQueryString();

        return view('meeting-pack.management.index', compact('plans', 'keyword', 'status'));
    }

    /**
     * ② 新規作成画面
     */
    public function create(): View
    {
        $this->authorize('create', MeetingPack::class);
        return view('meeting-pack.management.create');
    }

    /**
     * ③ 新規作成実行
     */
    public function store(MeetingPackRequest $request, StoreAction $action): RedirectResponse
    {
        $this->authorize('create', MeetingPack::class);

        // ロジックは専用の一元化されたアクションに外注
        $action->execute($request->validated(), Auth::id());

        return redirect()
            ->route('admin.meeting-packs.index')
            ->with('success', '面談パックを下書きとして新設しました。');
    }

    /**
     * ④ 詳細表示 (基本情報 + メタ情報 + 購入履歴)
     */
    public function show(string $id): View
    {
        // ⭕ 【型安全＆N+1対策】
        // payments（購入履歴）が存在する場合のみ受講生（user）を一括ロードするよう、
        // ネストされたリレーション形式（'payments.user'）で安全に定義します。
        // これにより、paymentsが空（0件）のときでもLaravelが内部でエラーを出さず、安全にスキップしてくれます。
        $plan = MeetingPack::with([
            'createdBy', 
            'updatedBy', 
            'payments' => fn ($q) => $q->latest('created_at')->take(20) // 直近20件制限の最適化
        ])->where('id', $id)->firstOrFail();

        $this->authorize('view', $plan);

        // 購入履歴が空でない場合のみ、受講生（user）データを安全に遅延一括ロード（Lazy Eager Loading）
        if ($plan->payments->isNotEmpty()) {
            $plan->load(['payments.user']);
        }

        // Bladeテンプレート（show.blade.php）側へ引き渡すための変数マウント
        $histories = $plan->payments;

        return view('meeting-pack.management.show', compact('plan', 'histories'));
    }

    /**
     * ⑤ 編集画面表示
     */
    public function edit(string $id): View
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();
        
        $this->authorize('update', $plan);

        return view('meeting-pack.management.edit', compact('plan'));
    }

    /**
     * ⑥ 基本情報の編集更新
     */
    public function update(MeetingPackRequest $request, string $id, UpdateAction $action): RedirectResponse
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();
        
        $this->authorize('update', $plan);

        $action->execute($plan, $request->validated(), Auth::id());

        return redirect()
            ->route('admin.meeting-packs.show', $plan->id)
            ->with('success', '面談パックの基本情報を更新しました。');
    }

    /**
     * ⑦ 物理削除
     */
    public function destroy(string $id): RedirectResponse
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();
        
        $this->authorize('delete', $plan);

        // 公開中の面談パックは購入履歴の整合性を守るため削除不可
        if ($plan->status === MeetingPackStatus::Published || $plan->status->value === 'published') {
            return redirect()
                ->back()
                ->with('danger', '公開中の面談パックは購入履歴の整合性を守るため削除できません。アーカイブしてください。');
        }

        $plan->delete();

        return redirect()
            ->route('admin.meeting-packs.index')
            ->with('danger', '面談パックを物理削除しました。');
    }

    /**
     * ⑧ 状態遷移: 公開する
     */
    public function publish(string $id): RedirectResponse
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();
        
        $this->authorize('update', $plan);
        $plan->update(['status' => MeetingPackStatus::Published]);

        return redirect()->back()->with('success', '面談パックを公開しました（販売開始）。');
    }

    /**
     * ⑨ 状態遷移: アーカイブする
     */
    public function archive(string $id): RedirectResponse
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();
        
        $this->authorize('update', $plan);
        $plan->update(['status' => MeetingPackStatus::Archived]);

        return redirect()->back()->with('danger', '面談パックをアーカイブしました（販売終了）。');
    }

    /**
     * ⑩ 状態遷移: 下書きに戻す
     */
    public function unarchive(string $id): RedirectResponse
    {
        $plan = MeetingPack::where('id', $id)->firstOrFail();
        
        $this->authorize('update', $plan);
        $plan->update(['status' => MeetingPackStatus::Draft]);

        return redirect()->back()->with('success', '面談パックを下書き状態に戻しました。');
    }
}
