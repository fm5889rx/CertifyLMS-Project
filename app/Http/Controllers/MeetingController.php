<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Meeting\CancelMeetingAction;
use App\Actions\Meeting\FetchAvailabilityAction;
use App\Actions\Meeting\IndexCoachMeetingAction;
use App\Actions\Meeting\IndexMeetingAction;
use App\Actions\Meeting\ShowMeetingAction;
use App\Actions\Meeting\StoreMeetingAction;
use App\Actions\Meeting\UpsertMeetingMemoAction;
use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;             // T-A-02：追加
use App\Http\Requests\Meeting\UpsertMemoRequest;        // T-A-02：追加
use App\Models\Certification;              // T-A-02：追加
use App\Models\Enrollment;             // T-A-02：追加
use App\Models\Meeting;            // T-A-02：追加
use App\Models\User;        // T-A-02：追加
use App\Services\MeetingQuotaService;        // T-A-02：追加
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 1on1 面談予約 (Meeting) の HTTP エントリポイント。（B-B-10修正版 → T-A-02修正版）
 *
 * 受講生視点(index / show / create / store / cancel / fetchAvailability)とコーチ視点
 * (indexAsCoach / upsertMemo)を 1 Controller に集約する。予約 / キャンセル / メモ保存の
 * 状態変更系は残面談回数の消費・返却、通知発火、トランザクション境界を method 内で扱い、
 * 取得系はクエリ組み立てを method 内で行う。認可は $this->authorize() または FormRequest::authorize()。
 *
 * T-A-02で変更：各メソッドの内部ロジックをアクション化する。
 */
class MeetingController extends Controller
{
    /**
     * 受講生本人の面談一覧。filter (upcoming/past/all) クエリで履歴を切り替える。
     * T-A-02：内部ロジックをアクション化する。
     */
    public function index(IndexRequest $request, MeetingQuotaService $meetingQuota, IndexMeetingAction $action): View
    {
        $filter = $request->validated('filter') ?? 'upcoming';
        $meetings = $action($request->user(), $filter, 20);

        return view('meeting.index', [
            'meetings' => $meetings,
            'filter' => $filter,
            'meetingsRemaining' => $meetingQuota->remaining($request->user()),
        ]);
    }

    /**
     * コーチ宛の面談一覧。担当受講生 / 受講登録での絞り込みを併せて提供する。
     * T-A-02；内部ロジックをアクション化する。
     */
    public function indexAsCoach(IndexAsCoachRequest $request, IndexCoachMeetingAction $action): View
    {
        $meetings = $action($request->user(), $request->validated(), 20);

        return view('meeting.coach.index', [
            'meetings' => $meetings,
            'filter' => $request->validated('filter') ?? 'upcoming',
            'studentFilter' => $request->validated('student'),
            'enrollmentFilter' => $request->validated('enrollment'),
        ]);
    }

    /**
     * 面談詳細(当事者共通)。Policy で coach/student の閲覧範囲を絞る。
     * T-A-02：内部ロジックを亜アクション化する。
     */
    public function show(Meeting $meeting, ShowMeetingAction $action): View
    {
        $this->authorize('view', $meeting);

        return view('meeting.show', ['meeting' => $action($meeting)]);
    }

    /**
     * 予約画面(受講生): URL に Enrollment を含む正規ルートで表示する。
     */
    public function create(Enrollment $enrollment, MeetingQuotaService $meetingQuota): View
    {
        $this->authorize('create', Meeting::class);
        abort_unless($enrollment->user_id === auth()->id(), 403);
        abort_unless($enrollment->status === EnrollmentStatus::Learning, 403);

        $enrollment->loadMissing('certification');

        return view('meeting.create', [
            'enrollment' => $enrollment,
            'meetingsRemaining' => $meetingQuota->remaining(auth()->user()),
        ]);
    }

    /**
     * 予約画面のエントリポイント(URL に Enrollment 無し)。
     * `resolve-default-enrollment` Middleware が default 資格に redirect するため、
     * 本 method に到達するのは default 未設定 + 残存 Enrollment が 0 件 or 2+ 件のケース。
     */
    public function createFallback(): View
    {
        $enrollments = auth()->user()?->enrollments()
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->with('certification')->get();

        return view('meeting.empty-state', ['enrollments' => $enrollments ?? collect()]);
    }

    /**
     * 受講生の予約申請。残面談回数を確認し、空き枠から過去実績最少のコーチを自動割当して reserved で確定する。
     * 同時刻 race condition は (coach_id, scheduled_at) UNIQUE 違反として検知し 409 へ変換する。
     * T-A-02：内部ロジックをアクション化する。
     */
    public function store(Enrollment $enrollment, StoreRequest $request, StoreMeetingAction $action): RedirectResponse
    {
        $scheduledAt = Carbon::parse($request->validated('scheduled_at'));
        $meeting = $action($enrollment, $scheduledAt, $request->validated('topic'));

        return redirect()->route('meetings.show', $meeting)->with('success', '面談を予約しました。');
    }

    /**
     * 当事者(受講生 or コーチ)による面談キャンセル。
     * reserved かつ開始前のみキャンセル可。消費済の面談回数 1 回分を返却する。
     * T-A-02：内部ロジックをアクション化する。
     */
    public function cancel(Meeting $meeting, CancelMeetingAction $action): RedirectResponse
    {
        $this->authorize('cancel', $meeting);
        $action($meeting, auth()->user());

        return redirect()->route('meetings.show', $meeting)->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    /**
     * 担当コーチによる面談メモ作成・更新。canceled の面談にはメモを残せない。
     * T-A-02：内部ロジックをアクション化する。
     */
    public function upsertMemo(Meeting $meeting, UpsertMemoRequest $request, UpsertMeetingMemoAction $action): RedirectResponse
    {
        $action($meeting, $request->validated('body'));

        return redirect()->route('meetings.show', $meeting)->with('success', '面談メモを保存しました。');
    }

    /**
     * 予約画面が呼ぶ空き枠取得 JSON エンドポイント。
     * T-A-02：内部ロジックをアクション化する。
     */
    public function fetchAvailability(Enrollment $enrollment, AvailabilityRequest $request, FetchAvailabilityAction $action): JsonResponse
    {
        $date = Carbon::parse($request->validated('date'));
        $slots = $action($enrollment, $date);

        return response()->json([
            'date' => $date->toDateString(),
            'slots' => $slots->map(fn (array $slot) => [
                'slot_start' => $slot['slot_start']->toIso8601String(),
                'slot_end' => $slot['slot_end']->toIso8601String(),
                'available_coach_count' => $slot['available_coach_count'],
            ])->all(),
        ]);
    }

    /**
     * 担当コーチ集合のうち、(1) 当該時刻に有効な availability 枠があり、
     * (2) 当該時刻に reserved / completed の Meeting を持たないコーチ集合を返す。
     *
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time) {
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get();
    }
}
