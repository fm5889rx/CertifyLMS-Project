<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus; // Enumをインポート
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
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Q&A掲示板（受講生・コーチ用）
 *
 * ルーティングは routes/web.php に記載
 */
class QaBoardController extends Controller
{
    /**
     * ① 質問一覧画面
     * GET /qa-board
     * 認可: 受講生 / コーチ共通
     */
    public function index(Request $request)
    {
        // レコードID（ULID）の昇順でソート
        $query = Question::with(['user', 'certification'])->withCount('replies')->orderBy('id', 'asc');

        // --- 1. クエリパラメータ（status）の解析処理 ---
        $queryString = $request->server('QUERY_STRING', '');

        // 空文字「status=」も確実にキャッチできるよう、0文字以上（*）の正規表現でパース
        preg_match_all('/status=([a-zA-Z]*)/', $queryString, $matches);

        // $matches の中身を確実に取得（例：['', 'unresolved']）
        $statusInputs = $matches[1] ?? [];

        $dbStatuses = [];
        $statusForBlade = '';

        // URLにstatusが2つ重なって届いているとき（未解決、または解決済を選択時）
        if (count($statusInputs) >= 2) {
            $realAction = strtolower($statusInputs[1]); // 2つ目の本当の検索条件を確実に見る

            if ($realAction === 'resolved') {
                $dbStatuses[] = QaThreadStatus::Resolved->value;
                $statusForBlade = 'resolved';
            } elseif ($realAction === 'unresolved' || $realAction === 'unresolve') {
                $dbStatuses[] = QaThreadStatus::Open->value;
                $statusForBlade = 'unresolved';
            }
        } else {
            // 「すべて」を選択している時
            $statusForBlade = '';
        }

        // データベースの絞り込みを適用
        if (! empty($dbStatuses)) {
            $query->whereIn('status', $dbStatuses);
        }

        // --- 3. 資格（certification_id）での絞り込み ---
        $selectedCertificationId = $request->input('certification_id', '');
        if (! empty($selectedCertificationId)) {
            $query->where('certification_id', $selectedCertificationId);
        }

        // --- 4. キーワード検索の処理 ---
        $keyword = $request->input('keyword', '');
        if (! empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'LIKE', "%{$keyword}%")->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        // --- 5. 5つのパラメータの準備（最終解決・確定版） ---

        // ① threads のページネーション
        $threads = $query->paginate(10);

        // クエリパラメータをそのままページネーターのリンクに付与するための処理
        // page=X という数字だけは正規表現で綺麗に除外してから合体させる
        $cleanQueryString = preg_replace('/&?page=[0-9]+/', '', $queryString);

        if (! empty($cleanQueryString)) {
            // ページネーターのベースURLに、生の検索条件をそのままドッキング
            $threads->withPath($request->url().'?'.$cleanQueryString);
        }

        // ② certifications: 資格マスターを全件取得
        $certifications = Certification::all();

        // ③ filters:
        $filters = [
            'status' => $statusForBlade,
            'certification_id' => $selectedCertificationId,
            'keyword' => $keyword,
        ];

        return view('qa-thread.index', [
            'threads' => $threads,
            'certifications' => $certifications,
            'filters' => $filters,
            'indexRoute' => 'qa-board.index',
            'publishedStatus' => CertificationStatus::Published,
        ]);
    }

    /**
     * ② 質問作成画面
     * GET /qa-board/create
     * 認可: 受講生のみ（ルーティングのミドルウェア等で制御）
     */
    public function create(): View
    {

        // ログインユーザーに紐づく本物の資格マスターのコレクションを取得
        $certifications = Certification::whereIn('id', function ($q) {
            $q->select('certification_id')
                ->from('certificates')
                ->where('user_id', Auth::id());
        })->get();

        // もし資格を1つも持っていなければマスターから全件取得して画面崩れを防ぐ
        if ($certifications->isEmpty()) {
            $certifications = Certification::all();
        }

        return view('qa-thread.create', compact('certifications'));
    }

    /**
     * ③ 質問の保存処理
     * POST /qa-board
     * 認可: 受講生のみ（ルーティングのミドルウェア等で制御）
     */
    public function store(QaThreadRequest $request): RedirectResponse
    {
        // 1. 本物の QaThreadRequest でバリデーション済みの安全なデータを一括取得
        $validated = $request->validated();

        // 2. ログインユーザーに紐づけて Question レコードを新規作成
        $question = Question::create([
            'id' => (string) Str::ulid(), // 保険としてここでも確実にULIDを生成
            'user_id' => Auth::id(),           // 投稿者のユーザーID
            'certification_id' => $validated['certification_id'] ?? null, // 画面から選択された資格マスターID
            'title' => $validated['title'],
            'body' => $validated['body'],   // カラム名・Bladeと統一した body
        ]);

        // 3. 投稿完了後は、作成された質問の「詳細画面（show）」へ自動遷移
        return redirect()->route('qa-board.show', $question->id)
            ->with('success', '質問を投稿しました。');
    }

    /**
     * ④ 質問詳細画面
     * GET /qa-board/{thread}
     * 認可: 受講生 / コーチ共通
     */
    public function show(string $id): View
    {
        // 1. 生のULIDを使って、データベースから質問を確実に1件引き抜く（なければ404エラーを出す）
        $thread = QaThread::where('id', $id)->with('user')->firstOrFail();

        // 2. 取得した質問IDに紐づく回答一覧を取得（最新順にソート）
        $replies = $thread->replies()->with(['user', 'thread', 'question'])->latest()->get();

        // 3.【超重要：bladeの編集リンクバグの強制無効化】
        // Bladeが回答のループ（$reply）の内側で、親を「$question」というプロパティ名で
        // 直に呼び出そうとしてクラッシュするのを防ぐため、各回答の中に親オブジェクトを直接埋め込む
        foreach ($replies as $reply) {
            $reply->question = $thread;
            $reply->thread = $thread;
        }

        // 4. bladeに必要な変数をセットして返却
        return view('qa-thread.show', [
            'thread' => $thread,
            'question' => $thread, // blade側で thread と question の両方の変数名で使えるようにする
            'replies' => $replies,
        ]);
    }

    /**
     * ⑤ 質問編集画面
     * GET /qa-board/{thread}/edit
     * 認可: 投稿者本人のみ（ルーティングの「can:update,question」で制御済み）
     */
    public function edit(string $id): View
    {
        // 1. データベースから確実に質問を取得
        $thread = Question::where('id', $id)->firstOrFail();

        // 2. ここで明示的にポリシー（QuestionPolicy::update）を呼び出す
        // ログインユーザーが投稿者本人でなければ、自動的にここで403エラー画面を返す
        $this->authorize('update', $thread);

        $certifications = Certification::all();

        return view('qa-thread.edit', compact('thread', 'certifications'));
    }

    /**
     * ⑥ 質問の更新処理
     * PATCH /qa-board/{thread}
     * 認可: 投稿者本人のみ（ルーティングの「can:update,question」で制御済み）
     */
    public function update(QaThreadRequest $request, string $id): RedirectResponse
    {
        $thread = Question::where('id', $id)->firstOrFail();

        // ここでも更新処理の前にポリシーを呼び出して防御
        $this->authorize('update', $thread);

        $thread->update($request->validated());

        return redirect()->route('qa-board.show', $thread->id)
            ->with('success', '質問を更新しました。');
    }

    /**
     * ⑦ 質問の削除処理
     * DELETE /qa-board/{thread}
     * 認可: 投稿者本人のみ
     * 質問スレッドの削除
     */
    public function destroy(string $id): RedirectResponse
    {
        $thread = Question::where('id', $id)->firstOrFail();

        // 削除処理の前にもポリシーを呼び出して防御
        $this->authorize('update', $thread);

        $thread->delete();

        return redirect()->route('qa-board.index')
            ->with('success', '質問を削除しました。');
    }

    /**
     * ⑧ 質問の通知処理（解決済み）
     * POST /qa-board/{question}/resolve
     * 認可: 投稿者本人のみ
     * 質問を「解決済」にする
     */
    public function resolve(string $id): RedirectResponse
    {
        // 1. 生のULIDを使って、データベースから対象の質問（QaThread）を特定
        $thread = QaThread::where('id', $id)->firstOrFail();

        // 2. 認可チェック（投稿者本人かどうかのPolicyを発動）
        $this->authorize('resolve', $thread);

        // 3. ステータスを「Resolved」に更新
        $thread->update([
            'status' => QaThreadStatus::Resolved,
            'resolved_at' => now(),
        ]);

        // 4. 処理完了後は、直前の詳細画面へスムーズに戻す（メッセージ付き）
        return back()->with('success', '質問を解決済にしました。');
    }

    /**
     * ⑨ 質問の通知処理（未解決）
     * POST /qa-board/{question}/unresolve
     * 認可: 投稿者本人のみ
     * 質問を「受付中（未解決）」に戻す
     */
    public function unresolve(string $id): RedirectResponse
    {
        $thread = QaThread::where('id', $id)->firstOrFail();

        // 認可チェック（Policyを発動）
        $this->authorize('unresolve', $thread);

        // ステータスを「Open（受付中）」に戻す
        $thread->update([
            'status' => QaThreadStatus::Open,
            'resolved_at' => null, // 解決日時をリセット
        ]);

        return back()->with('success', '質問を受付中に戻しました。');
    }

    // ============================================================
    // --- ここから回答（Reply）用のメソッド ---
    // ============================================================

    /**
     * ⑩ 回答（リプライ）の保存処理
     * POST /qa-board/{thread}/replies
     * 認可: 受講生 / コーチ共通
     */
    public function storeReply(QaReplyRequest $request, string $id): RedirectResponse
    {
        // 生のULIDから親の質問を確実に特定
        $thread = Question::where('id', $id)->firstOrFail();

        // スレッドのステータスが解決済（resolved）の場合は、データベースに保存される手前で
        // 処理を安全にブロック（403エラーを発生、または元の画面へリダイレクト）させる
        if ($thread->status === QaThreadStatus::Resolved) {
            return redirect()->back()->with('danger', '解決済みの質問には回答できません');
        }

        // 回答を新規作成
        Answer::create([
            'id' => (string) Str::ulid(), // 回答自体の新しいULIDを発行
            'question_id' => $thread->id,          // 親スレッドのID
            'user_id' => Auth::id(),           // ログイン中の回答者ユーザーID
            'body' => $request->validated()['body'], // 統一された本文（body）
        ]);

        // スレッドの所有者ユーザのIDを取り出す
        $threadUser = User::find($thread->user_id);
        if ($threadUser) {
            // プロジェクトに元々含まれている本物の通知クラス（ QaReplyPostedNotification ）を発火
            // 第2引数に「質問スレッドオブジェクト」を要求している場合は、そのまま $thread を渡す
            if (class_exists(QaReplyPostedNotification::class)) {
                $threadUser->notify(new QaReplyPostedNotification($thread));
            }
        }

        // 元々のリダイレクト処理
        return redirect()->route('qa-board.show', $thread->id)
            ->with('success', '回答を投稿しました。');
    }

    /**
     * 11. 回答（リプライ）の編集画面
     * GET /qa-board/{thread}/replies/{reply}/edit
     * 認可: 投稿者本人のみ（ルーティングの「can:update,reply」で制御済み）
     */
    public function editReply(string $id, string $replyId): View
    {
        // それぞれ生のULIDから、対象の質問と回答を取得
        $thread = QaThread::where('id', $id)->firstOrFail();
        $reply = Answer::where('id', $replyId)->firstOrFail();

        // ログインユーザーが回答の投稿者本人でなければ、ここで403を返す
        $this->authorize('update', $reply);

        return view('qa-thread.reply-edit', compact('thread', 'reply'));
    }

    /**
     * 12. 回答（リプライ）の更新処理
     * PATCH /qa-board/{question}/replies/{reply}
     * 認可: 投稿者本人のみ
     */
    public function updateReply(QaReplyRequest $request, string $id, string $replyId): RedirectResponse
    {
        // それぞれ生のULIDから、対象の質問と回答を取得
        $thread = QaThread::where('id', $id)->firstOrFail();
        $reply = Answer::where('id', $replyId)->firstOrFail();

        // 更新処理の前にポリシーを呼び出して防御
        $this->authorize('update', $reply);

        // バリデーション済みのデータで回答を更新
        $reply->update($request->validated());

        return redirect()->route('qa-board.show', $thread)
            ->with('success', '回答を更新しました。');
    }

    /**
     * 13. 回答の削除
     * DELETE /qa-board/{question}/replies/{reply}
     * 認可: 投稿者本人のみ
     */
    public function destroyReply(string $id, string $replyId): RedirectResponse
    {
        // それぞれ生のULIDから、対象の質問と回答を取得
        $thread = Question::where('id', $id)->firstOrFail();
        $reply = Answer::where('id', $replyId)->firstOrFail();

        // 削除処理の前にポリシー（delete）を呼び出して防御
        $this->authorize('delete', $reply);

        // 回答を削除
        $reply->delete();

        return redirect()->route('qa-board.show', $thread->id)
            ->with('success', '回答を削除しました。');
    }
}
