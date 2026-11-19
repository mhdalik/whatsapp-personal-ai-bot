<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingWhatsappMessage extends Model
{
    protected $table = 'outgoing_whatsapp_messages';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'meta' => 'array'];
    }
}
