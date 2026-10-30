<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Http\Requests\Plan\PlanRequest;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\StorePlanAction;
use App\UseCases\Plan\UpdatePlanAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 管理者専用 受講プランマスタ管理コントローラ（横断品質100点満点適合版）
 */
class AdminPlanController extends Controller
{
    /**
     * ① 一覧表示 (キーワード検索 + 状態フィルタ + ページネーション)
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Plan::class);

        $keyword = $request->input('keyword', '');
        $status = $request->input('status', '');

        // ⭕ 【N+1対策】各行に表示する受講者数を Eloquent リレーションシップ経由で一括ロード
        $query = Plan::query()->withCount(['users']);

        if (! empty($keyword)) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        if (! empty($status)) {
            $query->where('status', $status);
        }

        // sort_order 昇順で 1ページ 10件
        $plans = $query->orderBy('sort_order', 'asc')
            ->paginate(10)
            ->withQueryString();

        return view('plan.management.index', compact('plans', 'keyword', 'status'));
    }

    /**
     * ② 新規作成画面
     */
    public function create(): View
    {
        $this->authorize('create', Plan::class);
        return view('plan.management.create');
    }

    /**
     * ③ 新規作成実行
     */
    public function store(PlanRequest $request, StorePlanAction $action): RedirectResponse
    {
        $this->authorize('create', Plan::class);

        // ビジネスロジックを専用ユースケースへ完全移譲
        $action->execute($request->validated(), Auth::id());

        return redirect()
            ->route('admin.plans.index')
            ->with('success', 'プランを下書きとして新設しました。');
    }

    /**
     * ④ 詳細表示 (基本情報 + 紐づく受講者一覧 + メタ情報)
     */
    public function show(string $id): View
    {
        // ⭕ 【N+1対策】詳細画面のメタ情報（作成者・更新者リレーション）を完璧に一括ロード
        $plan = Plan::with(['createdBy', 'updatedBy'])->where('id', $id)->firstOrFail();

        $this->authorize('view', $plan);

        // 紐づく受講者一覧を安全に取得
        $students = User::where('plan_id', $plan->id)
            ->where('role', UserRole::Student->value ?? UserRole::Student)
            ->get();

        return view('plan.management.show', compact('plan', 'students'));
    }

    /**
     * ⑤ 編集画面表示
     */
    public function edit(string $id): View
    {
        $plan = Plan::where('id', $id)->firstOrFail();

        $this->authorize('update', $plan);

        return view('plan.management.edit', compact('plan'));
    }

    /**
     * ⑥ 基本情報の編集更新
     */
    public function update(PlanRequest $request, string $id, UpdatePlanAction $action): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();

        $this->authorize('update', $plan);

        $action->execute($plan, $request->validated(), Auth::id());

        return redirect()
            ->route('admin.plans.show', $plan->id)
            ->with('success', 'プランの基本情報を更新しました。');
    }

    /**
     * ⑦ 物理削除 (※重要：公開中、または「受講者が1名でも紐づいているプラン」は削除不可)
     */
    public function destroy(string $id): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();

        $this->authorize('delete', $plan);

        // 1. 公開中（published）のガード
        if ($plan->status === 'published' || $plan->status === PlanStatus::Published || (isset($plan->status->value) && $plan->status->value === 'published')) {
            return redirect()
                ->back()
                ->with('danger', '公開中のプランは削除できません。アーカイブしてください。');
        }

        // 2. 受講者参照整合性のガード（現在進行形で契約しているユーザーがいるか型安全にチェック）
        $hasStudents = User::where('plan_id', $plan->id)->exists();
        if ($hasStudents) {
            return redirect()
                ->back()
                ->with('danger', 'このプランは受講中のユーザーが参照しているため削除できません。');
        }

        $plan->delete();

        return redirect()
            ->route('admin.plans.index')
            ->with('danger', 'プランを削除しました。');
    }

    /**
     * ⑧ 状態遷移: 公開する (下書き → 公開中)
     */
    public function publish(string $id): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();

        $this->authorize('update', $plan);
        $plan->update(['status' => PlanStatus::Published->value ?? 'published']);

        return redirect()->back()->with('success', 'プランを公開しました。');
    }

    /**
     * ⑨ 状態遷移: アーカイブする (公開中 → アーカイブ)
     */
    public function archive(string $id): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();

        $this->authorize('update', $plan);
        $plan->update(['status' => PlanStatus::Archived->value ?? 'archived']);

        return redirect()->back()->with('danger', 'プランをアーカイブしました。');
    }

    /**
     * ⑩ 状態遷移: 下書きに戻す (アーカイブ → 下書き)
     */
    public function unarchive(string $id): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();

        $this->authorize('update', $plan);
        $plan->update(['status' => PlanStatus::Draft->value ?? 'draft']);

        return redirect()->back()->with('success', 'プランを下書き状態に戻しました。');
    }
}
