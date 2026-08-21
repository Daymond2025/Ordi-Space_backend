<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationOrdispace extends Model
{
    protected $table = 'notifications_ordispace';
    public $timestamps = false;

    protected $fillable = ['user_id', 'type_notification', 'contenu', 'lu', 'date_envoi'];

    protected function casts(): array
    {
        return ['lu' => 'boolean', 'date_envoi' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
