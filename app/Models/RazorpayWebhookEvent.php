<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source', 'event_id', 'type', 'payload', 'processed_at', 'result'])]
class RazorpayWebhookEvent extends Model
{
    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
