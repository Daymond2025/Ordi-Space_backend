<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAssistantIa extends Model
{
    protected $table = 'messages_assistant_ia';
    public $timestamps = false;

    protected $fillable = ['client_id', 'role', 'contenu', 'date_envoi'];

    protected function casts(): array
    {
        return ['date_envoi' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }
}
