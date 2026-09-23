<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Models\Answer;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\Question;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View as IlluminateView;

class AdminQaBoardController extends Controller
{
    /**
     * GET /admin/qa-board
     * 管理者用 質問スレッド一覧画面
     */
    public function index(Request $request): IlluminateView
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
     * GET /admin/qa-board/{thread}
     * 管理者用 質問スレッド詳細画面
     */
    public function show(string $id): IlluminateView
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
     * DELETE /admin/qa-board/{thread}
     * 管理者権限による 質問スレッドの強制削除
     */
    public function destroy(string $thread_id): RedirectResponse
    {
        // 1. データベースから本物の質問レコードを確実に特定（なければ404）
        $thread = Question::where('id', $thread_id)->firstOrFail();

        // 2. データベースの依存関係（制約エラー）を防ぐため、
        //    親スレッドに紐づいている回答（Answer）を先に一括で物理削除します
        Answer::where('question_id', $thread->id)->delete();

        // 3. 親スレッド本体を削除
        $thread->delete();

        // 4. 一覧画面へ戻し、フラッシュメッセージ（danger）をセット
        return redirect()->route('admin.qa-board.index')
            ->with('danger', '管理権限により質問スレッドを強制削除しました。');
    }

    /**
     * DELETE /admin/qa-board/{thread}/replies/{reply}
     * 管理者権限による 特定の回答の強制削除
     */
    public function destroyReply(string $thread_id, string $reply_id): RedirectResponse
    {
        // 1. 削除対象の回答レコードをピンポイントで特定
        $reply = Answer::where('id', $reply_id)
            ->where('question_id', $thread_id)
            ->firstOrFail();

        // 2. 回答をデータベースから物理削除
        $reply->delete();

        // 3. 親スレッドの詳細画面へ戻し、フラッシュメッセージをセット
        return redirect()->route('admin.qa-board.show', ['thread' => $thread_id])
            ->with('danger', '管理権限により不適切な回答を削除しました。');
    }
}
