<?php

declare(strict_types=1);

namespace App\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateAlreadyIssuedException;
use App\Exceptions\Certification\EnrollmentNotPassedException;
use App\Models\Certificate;
use App\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage; // 💡 物理層パッキング用に追記
use Illuminate\Support\Str;
use Mpdf\Mpdf; // 💡 PDF配信用に追記

/**
 * 修了証を発行するユースケース。
 */
final class IssueAction
{
    /**
     * @throws EnrollmentNotPassedException 受講登録が修了状態ではない
     * @throws CertificateAlreadyIssuedException 同一 Enrollment で修了証が既発行
     */
    public function __invoke(Enrollment $enrollment): Certificate
    {
        if ($enrollment->status !== EnrollmentStatus::Passed || $enrollment->passed_at === null) {
            throw new EnrollmentNotPassedException;
        }

        return DB::transaction(function () use ($enrollment) {
            // 二重発行ガード: lockForUpdate で同時呼出を直列化
            $existing = Certificate::query()
                ->where('enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new CertificateAlreadyIssuedException;
            }

            // 💡【解決】他メンバーの既存パスロジック（Str::ulid()）を100%完全に継承
            $pdfPath = 'certificates/'.Str::ulid().'.pdf';

            $certificate = Certificate::create([
                'user_id' => $enrollment->user_id,
                'enrollment_id' => $enrollment->id,
                'certification_id' => $enrollment->certification_id,
                'pdf_path' => $pdfPath,
                'issued_at' => now(),
            ]);

            // 💡【S-A-04 仕様完全適合】：発行フローの同一トランザクション内でPDF実体を非公開生成
            $html = view('certificates.pdf', [
                'certificate' => $certificate,
            ])->render();

            // 日本語フォントを物理層で強制上書き
            $mpdf = new Mpdf([
                'mode' => 'ja+a4',
                'format' => 'A4',
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'default_font' => 'ja',
            ]);

            $mpdf->WriteHTML($html);

            // 💡 public ディスクではなく、セキュリティ要件に指定された非公開の「private」ディスクへ確実に保存！
            Storage::disk('private')->put($pdfPath, $mpdf->output('', 'S'));

            return $certificate;
        });
    }
}
