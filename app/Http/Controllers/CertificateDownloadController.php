<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CertificateDownloadController extends Controller
{
    /**
     * 修了証 PDF を安全にプライベート領域からファイル添付形式でダウンロード配信
     */
    public function download(Request $request, string $id): BinaryFileResponse
    {
        $certificate = Certificate::findOrFail($id);

        // 1. マルチロール認可ポリシーの執行
        $this->authorize('download', $certificate);

        if (empty($certificate->pdf_path) || ! Storage::disk('private')->exists($certificate->pdf_path)) {

            // 一意の物理格納用パスを決定
            $cleanPath = 'certificates/certificate_'.$certificate->id.'.pdf';

            // 提供済みの PDF 用 Blade テンプレートを HTML 文字列化
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

            Storage::disk('private')->put($cleanPath, $mpdf->output('', 'S'));

            // データベースのパスを更新して同期
            $certificate->update(['pdf_path' => $cleanPath]);
            $certificate->pdf_path = $cleanPath;
        }

        // private ディスクから物理的な絶対パスを安全に生成
        $absoluteFilePath = Storage::disk('private')->path($certificate->pdf_path);

        $downloadFileName = 'certificate-'.$certificate->id.'.pdf';

        return response()->download($absoluteFilePath, $downloadFileName, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
