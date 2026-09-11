<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AIチャットにおける発言者の役割を司るEnum
 */
enum AiChatMessageRole: string
{
    case User = 'user';
    case Model = 'model';
    case Assistant = 'assistant';
}
