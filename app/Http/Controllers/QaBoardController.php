<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Http\Requests\QaThread\IndexRequest;
use App\Http\Requests\QaThread\QaReplyRequest;
use App\Http\Requests\QaThread\QaThreadRequest;
use App\Models\Answer;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\Question;
use App\UseCases\QaBoard\IndexAction;
use App\UseCases\QaBoard\ShowAction;
use App\UseCases\QaBoard\StoreReplyAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Q&A掲示板（受講生・コーチ用・管理者用共通）
 */
class QaBoardController extends Controller
{
    /**
     * Displays the role-scoped, validated Q&A listing.
     */
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $user = Auth::user();

        return view('qa-thread.index', $action($user, $request->validated()));
    }

    /**
     * Displays the question creation form with published certifications.
     */
    public function create(): View
    {
        $certifications = Certification::where('status', 'published')->get();

        return view('qa-thread.create', compact('certifications'));
    }

    /**
     * Creates a question from validated request data.
     */
    public function store(QaThreadRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $validated = $request->validated();
        $certification = Certification::findOrFail($validated['certification_id']);

        if (! $user->isAdmin() && $certification->status !== 'published' && $certification->status !== CertificationStatus::Published) {
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
     * Displays a thread and its ordered replies after visibility checks.
     */
    public function show(string $id, ShowAction $action): View
    {
        $user = Auth::user();
        $data = $action($user, $id);

        return view('qa-thread.show', [
            'thread' => $data['thread'],
            'question' => $data['thread'],
            'replies' => $data['replies'],
        ]);
    }

    /**
     * Displays the question edit form after policy authorization.
     */
    public function edit(string $id): View
    {
        $thread = Question::where('id', $id)->firstOrFail();
        $this->authorize('update', $thread);

        $certifications = Certification::where('status', 'published')->get();

        return view('qa-thread.edit', compact('thread', 'certifications'));
    }

    /**
     * Updates a question from validated request data.
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
     * Deletes a question when permitted by the moderation and ownership rules.
     */
    public function destroy(string $id): RedirectResponse
    {
        $user = Auth::user();
        $thread = Question::where('id', $id)->firstOrFail();

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
     * Marks a question as resolved.
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
     * Reopens a resolved question.
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
     * Stores a validated reply to an open question.
     */
    public function storeReply(QaReplyRequest $request, string $id, StoreReplyAction $action): RedirectResponse
    {
        $thread = Question::where('id', $id)->firstOrFail();

        if ($thread->status === QaThreadStatus::Resolved) {
            return redirect()->back()->with('error', '解決済みの質問には回答できません');
        }

        $action($thread, $request->user(), $request->validated('body'));

        return redirect()->route('qa-board.show', $thread->id)
            ->with('success', '回答を投稿しました。');
    }

    /**
     * Displays the reply edit form after policy authorization.
     */
    public function editReply(string $id, string $replyId): View
    {
        $thread = QaThread::where('id', $id)->firstOrFail();
        $reply = Answer::where('id', $replyId)->firstOrFail();

        $this->authorize('update', $reply);

        return view('qa-thread.reply-edit', compact('thread', 'reply'));
    }

    /**
     * Updates a reply from validated request data.
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
     * Deletes a reply after policy authorization.
     */
    public function destroyReply(string $id, string $replyId): RedirectResponse
    {
        $thread = Question::where('id', $id)->firstOrFail();
        $reply = Answer::where('id', $replyId)->firstOrFail();

        $this->authorize('delete', $reply);

        $reply->delete();

        return redirect()->route('qa-board.show', $thread->id)
            ->with('success', '回答を削除しました。');
    }
}
