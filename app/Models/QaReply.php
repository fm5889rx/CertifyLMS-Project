<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Answer;
use Illuminate\Database\Eloquent\Concerns\HasUlids; // ULIDを使用するためのトレイトをインポート
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QaReply extends Answer
{
    use HasFactory;
    use HasUlids;  // ULIDを使用するためのトレイトを追加

    // このモデルの操作先は「answers」テーブルであると指定
    protected $table = 'answers';

    protected $keyType = 'string';
    public $incrementing = false;
}
