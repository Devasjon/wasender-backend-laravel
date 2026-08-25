<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['bill_id', 'event', 'sent_date', 'status', 'detail'])]
class ReminderLog extends Model
{
    protected function casts(): array
    {
        return [
            'sent_date' => 'date',
        ];
    }
}
