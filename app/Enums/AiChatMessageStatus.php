<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AIの応答状態を司るEnum
 */
enum AiChatMessageStatus: string
{
    case Completed = 'completed'; // 正常完了
    case Pending   = 'pending';   // 応答生成中
    case Error     = 'error';     // エラー発生
}
