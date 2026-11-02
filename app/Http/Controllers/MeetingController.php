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
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;
use App\Http\Requests\Meeting\UpsertMemoRequest;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Services\MeetingQuotaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Handles student and coach meeting workflows.
 */
class MeetingController extends Controller
{
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
     */
    public function store(Enrollment $enrollment, StoreRequest $request, StoreMeetingAction $action): RedirectResponse
    {
        try {
            $scheduledAt = Carbon::parse($request->validated('scheduled_at'));
            $meeting = $action($enrollment, $scheduledAt, $request->validated('topic'));

            return redirect()->route('meetings.show', $meeting)->with('success', '面談を予約しました。');

        } catch (QueryException) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'ご希望の時間帯は既に別の予約が入ったか、予約不可能な状態です。');

        } catch (\Exception $exception) {
            return redirect()->back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }
    }

    /**
     * 当事者(受講生 or コーチ)による面談キャンセル。
     */
    public function cancel(Meeting $meeting, CancelMeetingAction $action): RedirectResponse
    {
        $this->authorize('cancel', $meeting);
        $action($meeting, auth()->user());

        return redirect()->route('meetings.show', $meeting)->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    /**
     * 担当コーチによる面談メモ作成・更新。
     */
    public function upsertMemo(Meeting $meeting, UpsertMemoRequest $request, UpsertMeetingMemoAction $action): RedirectResponse
    {
        $action($meeting, $request->validated('body'));

        return redirect()->route('meetings.show', $meeting)->with('success', '面談メモを保存しました。');
    }

    /**
     * 予約画面が呼ぶ空き枠取得 JSON エンドポイント。
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
}
