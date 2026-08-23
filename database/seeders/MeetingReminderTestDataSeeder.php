<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Meeting;
use App\Models\Enrollment;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\MeetingStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MeetingReminderTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $inProgressStatus = UserStatus::InProgress;

        // 1. 各ロールユーザーを安全に確保
        $student = User::where('role', UserRole::Student)->first()
            ?? User::factory()->create(['role' => UserRole::Student, 'status' => $inProgressStatus, 'name' => 'リマインド受講生']);

        $coach = User::where('role', UserRole::Coach)->first()
            ?? User::factory()->create(['role' => UserRole::Coach, 'status' => $inProgressStatus, 'name' => 'リマインドコーチ']);

        // 2. 外部キー制約の網羅
        // 実在する本物の受講登録（Enrollment）を確保、
        // 万が一実在しない場合のみ、ファクトリ等で安全に紐づけ用レコードを牽引する
        $enrollment = Enrollment::where('user_id', $student->id)->first()
            ?? Enrollment::factory()->create(['user_id' => $student->id]);

        // 3. 物理マイグレーションでレコード作成
        // ① 前日（eve）配信対象：明日の「お昼12時00分」の予約済み面談
        Meeting::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $enrollment->id,
            'student_id'    => $student->id,
            'coach_id'      => $coach->id,
            'status'        => MeetingStatus::Reserved,
            'scheduled_at'  => now()->addDay()->setHour(12)->setMinute(0)->setSecond(0),
            'topic'         => '前日リマインダー検証用面談',
        ]);

        // ② 1時間前（one_hour_before）配信対象：今日の「今から30分後」の予約済み面談
        Meeting::create([
            'id'            => (string) Str::ulid(),
            'enrollment_id' => $enrollment->id,
            'student_id'    => $student->id,
            'coach_id'      => $coach->id,
            'status'        => MeetingStatus::Reserved,
            'scheduled_at'  => now()->addMinutes(30),
            'topic'         => '1時間前リマインダー検証用面談',
        ]);
    }
}
