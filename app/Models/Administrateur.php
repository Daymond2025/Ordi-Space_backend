<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Administrateur extends Model
{
    protected $table = 'administrateurs';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
