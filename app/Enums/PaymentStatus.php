<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * S-A-03 決済ステータス
 * データベース物理層（MySQL）に格納される小文字のマジック文字列を完全型ロック
 */
enum PaymentStatus: string
{
    case Completed = 'completed';
    case Pending   = 'pending';
    case Failed    = 'failed';

    /**
     * 各ステータスに対応する日本語の表示ラベルを返却
     */
    public function label(): string
    {
        return match ($this) {
            self::Completed => '決済完了',
            self::Pending   => '保留中',
            self::Failed    => '決済失敗',
        };
    }
}
