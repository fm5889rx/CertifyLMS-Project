<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids; // ULIDを使用するためのトレイトをインポート
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QaThread extends Question
{
    use HasFactory;
    use HasUlids;  // ULIDを使用するためのトレイトを追加

    protected $table = 'questions';

    protected $keyType = 'string';

    public $incrementing = false;
}
