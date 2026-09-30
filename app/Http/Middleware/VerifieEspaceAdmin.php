<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restreint une section de l'Admin Web à un "espace" donné — mais seulement
 * pour un acteur de type administrateur : les autres rôles qui partagent ces
 * mêmes routes (coordinateur, fournisseur…) ne sont jamais concernés, leurs
 * propres permissions Spatie continuent de les gouverner normalement.
 *
 * Un admin marqué super_admin (par défaut, y compris tous les admins déjà
 * existants — cf. migration) passe toujours ; un admin restreint doit avoir
 * l'espace dans sa liste `espaces_autorises`.
 */
class VerifieEspaceAdmin
{
    public function handle(Request $request, Closure $next, string $espace): Response
    {
        $user = $request->user();

        if (! $user || $user->type_utilisateur !== ROLE_ADMINISTRATEUR) {
            return $next($request);
        }

        abort_unless($user->administrateur?->peutAccederEspace($espace) ?? true, 403, "Accès à l'espace « {$espace} » non autorisé.");

        return $next($request);
    }
}
