<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Administrateur extends Model
{
    protected $table = 'administrateurs';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'est_super_admin', 'espaces_autorises'];

    protected function casts(): array
    {
        return [
            'est_super_admin' => 'boolean',
            'espaces_autorises' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Un admin non super-admin ne voit/peut agir que dans les espaces listés
     * ici (cf. VerifieEspaceAdmin) — un super-admin a toujours accès à tout.
     */
    public function peutAccederEspace(string $espace): bool
    {
        return $this->est_super_admin || in_array($espace, $this->espaces_autorises ?? [], true);
    }
}
