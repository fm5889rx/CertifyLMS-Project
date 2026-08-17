<?php

declare(strict_types=1);

namespace App\Enums;

// string型として定義
enum QaThreadStatus: string
{
    case Open = 'Open';       // 受付中
    case Unresolved = 'Unresolved'; // 未解決
    case Resolved = 'Resolved';   // 解決済
    case Closed = 'Closed';   // 締め切り

    // Bladeで日本語表示したい場合に便利なメソッド
    public function label(): string
    {
        return match ($this) {
            self::Open => '受付中',
            self::Unresolved => '未解決',
            self::Resolved => '解決済',
            self::Closed => '終了',
        };
    }
}
