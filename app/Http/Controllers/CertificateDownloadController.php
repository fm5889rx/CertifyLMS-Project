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
     * 修了証 PDF を安全にファイル添付形式でダウンロード配信
     */
    public function download(Request $request, string $id): BinaryFileResponse
    {
        // 引数で指定された id で certificate テーブルからレコードを取得
        $certificate = Certificate::findOrFail($id);

        // 1. 【セキュリティ監査】：既存の認可ポリシーで認可チェック
        $this->authorize('download', $certificate);

        // PDF ファイルがすでに存在するかチェック
        if (empty($certificate->pdf_path) || !Storage::disk('public')->exists($certificate->pdf_path)) {

            // 1. 他メンバーのデータ構造を汚さない、安全な一意の物理格納用パスを決定
            $cleanPath = 'certificates/certificate_' . $certificate->id . '.pdf';

            // 2. 提供済みの PDF 用 Blade テンプレートにデータをデリバリーして HTML 文字列化
            $html = view('certificates.pdf', [
                'certificate' => $certificate,
            ])->render();

            // 📄 CertificateDownloadController.php の mPDF 生成部分を以下に置換！

            // 👑 【シニアの mPDF フォントファミリー物理層強制上書き監査】
            $mpdf = new \Mpdf\Mpdf([
                'mode'             => 'ja+a4',
                'format'           => 'A4',
                'autoScriptToLang' => true,
                'autoLangToFont'   => true,
                'default_font'     => 'ja',
            ]);

            $mpdf->WriteHTML($html);
            Storage::disk('public')->put($cleanPath, $mpdf->output('', 'S'));
            $certificate->update(['pdf_path' => $cleanPath]);
            $certificate->pdf_path = $cleanPath;
        }

        // 👑 【物理的な絶対パスの完全生成】：null ではなく、本物の string パスが100%確実に引き渡されます！
        $absoluteFilePath = Storage::disk('public')->path($certificate->pdf_path);

        // 証書として相応しい、美しい日本語の日本語ダウンロードファイル名を動的に組み立て
        $downloadFileName = sprintf(
            '修了証_%s_%s.pdf',
            $certificate->user?->name ?? '受講生',
            $certificate->enrollment?->certification?->name ?? '資格'
        );

        // 🚀 2. 本物の「添付形式（Attachment）」パケットとして高速デリバリー！
        return response()->download($absoluteFilePath, $downloadFileName);
    }
}
