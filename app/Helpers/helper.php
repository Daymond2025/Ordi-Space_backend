<?php

use Illuminate\Http\JsonResponse;

/**
 * Fonctions utilitaires globales OrdiSpace.
 *
 * Chargé automatiquement via composer.json > autoload > files — utilisable
 * partout (contrôleurs, jobs, commandes artisan, observers) sans import.
 */

if (! function_exists('api_success')) {
    /**
     * Même enveloppe de succès que Controller::success(), pour le code qui
     * ne peut pas hériter du contrôleur de base (jobs, commandes, closures).
     */
    function api_success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['success' => true, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }
}

if (! function_exists('api_error')) {
    /**
     * Enveloppe d'erreur manuelle cohérente avec bootstrap/app.php, pour les
     * cas où lever une exception n'est pas pratique.
     */
    function api_error(string $code, string $message, int $status = 422, array $fields = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return response()->json(['success' => false, 'error' => $error], $status);
    }
}

if (! function_exists('generate_otp_code')) {
    /**
     * Génère un code numérique à OTP_LONGUEUR chiffres (2FA par e-mail).
     */
    function generate_otp_code(): string
    {
        return (string) random_int(
            (int) str_pad('1', OTP_LONGUEUR, '0'),
            (int) str_pad('', OTP_LONGUEUR, '9')
        );
    }
}

if (! function_exists('current_user')) {
    /**
     * Raccourci typé vers l'utilisateur authentifié courant (garde sanctum).
     */
    function current_user(): ?\App\Models\User
    {
        return auth('sanctum')->user() ?? auth()->user();
    }
}

if (! function_exists('user_has_role')) {
    /**
     * Vérifie le rôle de l'utilisateur courant (ou d'un utilisateur donné),
     * sans lever d'erreur si personne n'est authentifié.
     */
    function user_has_role(string $role, ?\App\Models\User $user = null): bool
    {
        $user ??= current_user();

        return (bool) $user?->hasRole($role);
    }
}

if (! function_exists('paginate_per_page')) {
    /**
     * Normalise le paramètre ?per_page= d'une requête (borné à PAGINATION_MAX).
     */
    function paginate_per_page(\Illuminate\Http\Request $request): int
    {
        return min($request->integer('per_page', PAGINATION_PAR_DEFAUT), PAGINATION_MAX);
    }
}
