<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use App\Http\Requests\Plan\PlanRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class AdminPlanController extends Controller
{
    /**
     * 一覧表示 (キーワード検索 + 状態フィルタ + ページネーション)
     * 各行に契約中の受講者数を表示するため、自動カウントを挟みます
     */
    public function index(Request $request): View
    {
        $keyword = $request->input('keyword', '');
        $status = $request->input('status', '');

        $query = Plan::query();

        // 既存のPlanモデルに定義されているであろう受講者リレーションを自動カウント
        if (method_exists(Plan::class, 'students')) {
            $query->withCount('students');
        } elseif (method_exists(Plan::class, 'users')) {
            $query->withCount('users');
        }

        // 1. キーワード検索（プラン名）
        if (!empty($keyword)) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        // 2. 状態フィルタ
        if (!empty($status)) {
            $query->where('status', $status);
        }

        // 3. 2件目の大成功変数名「$plans」で Blade へ引き渡します
        $plans = $query->orderBy('sort_order', 'asc')->paginate(10)->withQueryString();

        return view('plan.management.index', compact('plans', 'keyword', 'status'));
    }

    public function create(): View
    {
        return view('plan.management.create');
    }

    /**
     * 新規作成 (初期状態は下書き: draft)
     */
    public function store(PlanRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['id'] = (string) Str::ulid();
        $data['status'] = 'draft'; // 💡 初期状態は下書き固定

        // マイグレーション仕様に完全適合：作成者・更新者IDを自動マウント
        $adminId = Auth::id();
        $data['created_by_user_id'] = $adminId;
        $data['updated_by_user_id'] = $adminId;

        Plan::create($data);

        return redirect()->route('admin.plans.index')
            ->with('success', 'プランを下書きとして新設しました。');
    }

    /**
     * 詳細表示 (基本情報 + 紐づく受講者一覧 + メタ情報)
     */
    public function show(string $id): View
    {
        $plan = Plan::where('id', $id)->firstOrFail();

        // づく受講者一覧をBladeへマウント
        $students = User::where('plan_id', $plan->id)
            ->where('role', \App\Enums\UserRole::Student)
            ->get();

        return view('plan.management.show', compact('plan', 'students'));
    }

    public function edit(string $id): View
    {
        $plan = Plan::where('id', $id)->firstOrFail();
        return view('plan.management.edit', compact('plan'));
    }

    /**
     * 基本情報の編集更新（状態はこのフォームでは変更しない）
     */
    public function update(PlanRequest $request, string $id): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();

        $data = $request->validated();
        $data['updated_by_user_id'] = Auth::id();

        $plan->update($data);

        return redirect()->route('admin.plans.show', $plan->id)
            ->with('success', 'プランの基本情報を更新しました。');
    }

    /**
     * 物理削除 (※重要：公開中、または「受講者が1名でも紐づいているプラン」は削除不可)
     */
    public function destroy(string $id): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();

        // 1. 公開中（published）のガード
        if ($plan->status === 'published' || $plan->status === PlanStatus::Published) {
            return redirect()->back()
                ->with('danger', '公開中のプランは削除できません。アーカイブしてください。');
        }

        // 2. 受講者参照整合性のガード（現在進行形で契約しているユーザーがいるかチェック）
        $hasStudents = User::where('plan_id', $plan->id)->exists();
        if ($hasStudents) {
            return redirect()->back()
                ->with('danger', 'このプランは受講中のユーザーが参照しているため削除できません。');
        }

        $plan->delete();

        return redirect()->route('admin.plans.index')
            ->with('danger', 'プランを削除しました。');
    }

    /**
     * 状態遷移: 公開する (下書き → 公開中 'published')
     */
    public function publish(string $id): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();
        $plan->update(['status' => 'published']);

        return redirect()->back()->with('success', 'プランを公開しました。');
    }

    /**
     * 状態遷移: アーカイブする (公開中 → アーカイブ 'archived')
     */
    public function archive(string $id): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();
        $plan->update(['status' => 'archived']);

        return redirect()->back()->with('danger', 'プランをアーカイブしました。');
    }

    /**
     * 状態遷移: 下書きに戻す (アーカイブ → 下書き 'draft')
     */
    public function unarchive(string $id): RedirectResponse
    {
        $plan = Plan::where('id', $id)->firstOrFail();
        $plan->update(['status' => 'draft']);

        return redirect()->back()->with('success', 'プランを下書き状態に戻しました。');
    }
}
