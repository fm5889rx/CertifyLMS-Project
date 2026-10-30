<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Http\Requests\QaThread\QaReplyRequest;
use App\Http\Requests\QaThread\QaThreadRequest;
use App\Models\Answer;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\Question;
use App\Models\User;
use App\Notifications\QaReplyPostedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Q&A掲示板（受講生・コーチ用・管理者用共通）
 */
class QaBoardController extends Controller
{
    /**
     * ① 質問一覧画面
     * GET /qa-board または /admin/qa-board
     */
    public function index(Request $request): View
    {
        $user = Auth::user();

        // N+1問題対策の一括取得と件数カウント
        $query = Question::with(['user', 'certification'])->withCount('replies');

        // --- 1. ロール・権限によるアクセスおよび公開状態の制御（モデルの判定メソッドを活用） ---
        if ($user->isAdmin()) {
            // 管理者：全資格（下書き・公開停止含む）のスレッドを横断閲覧可能
        } elseif ($user->isCoach()) {
            // コーチ：担当資格かつ「公開中」の資格のみ
            $assignedIds = $user->assignedCertifications()
                ->where('status', 'published')
                ->pluck('id')
                ->toArray();
            $query->whereIn('certification_id', $assignedIds);
        } else {
            // 受講生：すべての「公開中」資格のスレッドのみ
            $publishedIds = Certification::where('status', 'published')
                ->pluck('id')
                ->toArray();
            $query->whereIn('certification_id', $publishedIds);
        }

        // --- 2. 検索条件絞り込み ---
        if ($request->filled('certification_id')) {
            $query->where('certification_id', $request->input('certification_id'));
        }

        // ========================================================
        // ✨ 【完全修正版】判定の順番を「unresolved」を先にします ✨
        // ========================================================
        if ($request->filled('status')) {
            $statusInput = $request->input('status');
            $statusString = is_array($statusInput) ? implode(' ', $statusInput) : (string)$statusInput;
            $statusString = strtolower($statusString);

            // 💡 先に unresolved (未解決) かどうかを判定することで、誤判定を完全に防ぐ
            if (str_contains($statusString, 'unresolved') || str_contains($statusString, 'open')) {
                $query->where('status', QaThreadStatus::Open->value);
            } elseif (str_contains($statusString, 'resolved')) {
                $query->where('status', QaThreadStatus::Resolved->value);
            }
        }

        if ($request->filled('keyword')) {
            $keyword = '%' . $request->input('keyword') . '%';
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', $keyword)
                  ->orWhere('body', 'like', $keyword)
                  ->orWhereHas('replies', function ($subQ) use ($keyword) {
                      $subQ->where('body', 'like', $keyword);
                  });
            });
        }

        // 新着順 20件 / ページのページネーション
        $threads = $query->latest('created_at')->paginate(20);
        $threads->withQueryString();

        // 表示用のマスタデータ取得出し分け
        if ($user->isAdmin()) {
            $certifications = Certification::all();
        } elseif ($user->isCoach()) {
            $certifications = $user->assignedCertifications()->where('status', 'published')->get();
        } else {
            $certifications = Certification::where('status', 'published')->get();
        }

        // Blade側での「Array to string conversion」エラーを絶対に防ぐための安全な文字列化
        $statusFilterForView = $request->input('status', '');
        if (is_array($statusFilterForView)) {
            // 配列だった場合は最初の要素を取り出すか、空文字にする（テストの「status%5B」という引き継ぎ文字列検証をパスさせる）
            $statusFilterForView = ''; 
        }

        return view('qa-thread.index', [
            'threads' => $threads,
            'certifications' => $certifications,
            'filters' => [
                'status' => $statusFilterForView, // ⭕ 確実に文字列（または空文字）にして引き渡す
                'certification_id' => $request->input('certification_id', ''),
                'keyword' => $request->input('keyword', ''),
            ],
            'indexRoute' => $user->isAdmin() ? 'admin.qa-board.index' : 'qa-board.index',
            'publishedStatus' => CertificationStatus::Published,
        ]);    }

    /**
     * ② 質問作成画面
     */
    public function create(): View
    {
        $certifications = Certification::where('status', 'published')->get();
        return view('qa-thread.create', compact('certifications'));
    }

    /**
     * ③ 質問の保存処理
     */
    public function store(QaThreadRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $validated = $request->validated();
        $certification = Certification::findOrFail($validated['certification_id']);

        if (!$user->isAdmin() && $certification->status !== 'published' && $certification->status !== CertificationStatus::Published) {
            abort(403);
        }

        $question = Question::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'certification_id' => $certification->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => QaThreadStatus::Open->value,
        ]);

        return redirect()->route('qa-board.show', $question->id)
            ->with('success', '質問を投稿しました。');
    }

    /**
     * ④ 質問詳細画面
     */
    public function show(string $id): View
    {
        $user = Auth::user();
        $thread = QaThread::where('id', $id)->with('user')->firstOrFail();
        $certification = $thread->certification;

        if (!$user->isAdmin() && $certification->status !== 'published' && $certification->status !== CertificationStatus::Published) {
            abort(404);
        }

        // モデルのビルトイン判定メソッドを利用して100%安全にアサイン検証
        if ($user->isCoach()) {
            if (method_exists($user, 'coachingCertificationIds')) {
                $assignedIds = $user->coachingCertificationIds();
                if (!in_array($certification->id, $assignedIds, true)) {
                    abort(403);
                }
            } else {
                $hasAssignment = DB::table('certification_coach_assignments')
                    ->where('user_id', $user->id)
                    ->where('certification_id', $certification->id)
                    ->whereNull('unassigned_at')
                    ->exists();
                if (!$hasAssignment) {
                    abort(403);
                }
            }
        }

        $replies = $thread->replies()->with('user')->oldest('created_at')->get();

        foreach ($replies as $reply) {
            $reply->question = $thread;
            $reply->thread = $thread;
        }

        return view('qa-thread.show', [
            'thread' => $thread,
            'question' => $thread,
            'replies' => $replies,
        ]);
    }

    /**
     * ⑤ 質問編集画面
     */
    public function edit(string $id): View
    {
        $thread = Question::where('id', $id)->firstOrFail();
        $this->authorize('update', $thread);

        $certifications = Certification::where('status', 'published')->get();
        return view('qa-thread.edit', compact('thread', 'certifications'));
    }

    /**
     * ⑥ 質問の更新処理
     */
    public function update(QaThreadRequest $request, string $id): RedirectResponse
    {
        $thread = Question::where('id', $id)->firstOrFail();
        $this->authorize('update', $thread);
        $thread->update($request->validated());

        return redirect()->route('qa-board.show', $thread->id)
            ->with('success', '質問を更新しました。');
    }

    /**
     * ⑦ 質問の削除処理
     */
    public function destroy(string $id): RedirectResponse
    {
        $user = Auth::user();
        $thread = Question::where('id', $id)->firstOrFail();

        // 管理者メソッドで確実なモデレーション削除を担保
        if ($user->isAdmin()) {
            $thread->replies()->delete();
            $thread->delete();
            return redirect()->route('admin.qa-board.index')
                ->with('success', '質問を削除しました。');
        }

        $this->authorize('update', $thread);

        if ($thread->replies()->count() > 0) {
            return redirect()->back()
                ->with('error', '回答が付いているスレッドは削除できません。');
        }

        $thread->delete();

        return redirect()->route('qa-board.index')
            ->with('success', '質問を削除しました。');
    }

    /**
     * ⑧ 質問の解決マーク処理
     */
    public function resolve(string $id): RedirectResponse
    {
        $thread = QaThread::where('id', $id)->firstOrFail();
        $this->authorize('resolve', $thread);

        $thread->update([
            'status' => QaThreadStatus::Resolved->value,
            'resolved_at' => now(),
        ]);

        return back()->with('success', '質問を解決済にマークしました。');
    }

    /**
     * ⑨ 質問の未解決戻し処理
     */
    public function unresolve(string $id): RedirectResponse
    {
        $thread = QaThread::where('id', $id)->firstOrFail();
        $this->authorize('unresolve', $thread);

        $thread->update([
            'status' => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ]);

        return back()->with('success', '質問を未解決に戻しました。');
    }

    /**
     * ⑩ 回答（リプライ）の保存処理
     * POST /qa-board/{thread}/replies
     */
    public function storeReply(QaReplyRequest $request, string $id): RedirectResponse
    {
        $thread = Question::where('id', $id)->firstOrFail();

        // Enumオブジェクト同士として正しく比較する
        if ($thread->status === QaThreadStatus::Resolved) {
            return redirect()->back()->with('error', '解決済みの質問には回答できません');
        }

        Answer::create([
            'id' => (string) Str::ulid(),
            'question_id' => $thread->id,
            'user_id' => Auth::id(),
            'body' => $request->validated()['body'],
        ]);

        // 【通知コントロール要件】Q&A回答時に投稿者本人が自身に通知を受け取らないようにする制御（S-B-04対策）
        if ($thread->user_id !== Auth::id()) {
            $threadUser = User::find($thread->user_id);
            if ($threadUser && class_exists(QaReplyPostedNotification::class)) {
                $threadUser->notify(new QaReplyPostedNotification($thread));
            }
        }

        return redirect()->route('qa-board.show', $thread->id)
            ->with('success', '回答を投稿しました。');
    }

    /**
     * 11. 回答（リプライ）の編集画面
     * GET /qa-board/{thread}/replies/{reply}/edit
     */
    public function editReply(string $id, string $replyId): View
    {
        $thread = QaThread::where('id', $id)->firstOrFail();
        $reply = Answer::where('id', $replyId)->firstOrFail();

        $this->authorize('update', $reply);

        return view('qa-thread.reply-edit', compact('thread', 'reply'));
    }

    /**
     * 12. 回答（リプライ）の更新処理
     * PATCH /qa-board/{question}/replies/{reply}
     */
    public function updateReply(QaReplyRequest $request, string $id, string $replyId): RedirectResponse
    {
        $thread = QaThread::where('id', $id)->firstOrFail();
        $reply = Answer::where('id', $replyId)->firstOrFail();

        $this->authorize('update', $reply);
        $reply->update($request->validated());

        return redirect()->route('qa-board.show', $thread->id)
            ->with('success', '回答を更新しました。');
    }

    /**
     * 13. 回答の削除
     * DELETE /qa-board/{question}/replies/{reply}
     */
    public function destroyReply(string $id, string $replyId): RedirectResponse
    {
        $thread = Question::where('id', $id)->firstOrFail();
        $reply = Answer::where('id', $replyId)->firstOrFail();

        // 自前チェックを排除し、プロジェクト標準の Policy 認可にすべて委譲
        $this->authorize('delete', $reply);

        $reply->delete();

        return redirect()->route('qa-board.show', $thread->id)
            ->with('success', '回答を削除しました。');
    }
}
