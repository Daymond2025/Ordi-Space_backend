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

if (! function_exists('normaliser_telephone')) {
    /**
     * Normalise un numéro de téléphone en E.164 (ex. "07 79 36 38 09" →
     * "+2250779363809") — indispensable pour que la connexion par téléphone
     * retrouve toujours le même utilisateur quel que soit le format saisi, et
     * pour adresser correctement l'API WhatsApp (Twilio exige le E.164).
     */
    function normaliser_telephone(string $telephone): string
    {
        $nettoye = preg_replace('/[^\d+]/', '', $telephone) ?? '';

        if (str_starts_with($nettoye, '+')) {
            return $nettoye;
        }

        if (str_starts_with($nettoye, '00')) {
            return '+'.substr($nettoye, 2);
        }

        if (str_starts_with($nettoye, '0')) {
            return TELEPHONE_INDICATIF_PAYS_DEFAUT.substr($nettoye, 1);
        }

        return TELEPHONE_INDICATIF_PAYS_DEFAUT.$nettoye;
    }
}

if (! function_exists('normaliser_numero_ci')) {
    /**
     * E.164 pour wa.me, tel: et les transferts Mobile Money — un numéro local
     * commençant par 0 garde ce 0 (numérotation ivoirienne à 10 chiffres,
     * "+225 07 58 84 92 81"), contrairement à normaliser_telephone() qui le retire.
     */
    function normaliser_numero_ci(string $saisie): string
    {
        $chiffres = preg_replace('/\D/', '', $saisie) ?? '';

        if (str_starts_with(trim($saisie), '+')) {
            return '+'.$chiffres;
        }

        if (str_starts_with($chiffres, '00')) {
            return '+'.substr($chiffres, 2);
        }

        return TELEPHONE_INDICATIF_PAYS_DEFAUT.$chiffres;
    }
}

if (! function_exists('lien_whatsapp')) {
    /**
     * Lien de conversation WhatsApp (wa.me) d'un numéro — chiffres seuls,
     * indicatif pays compris, sans le "+". Null si aucun numéro.
     */
    function lien_whatsapp(?string $telephone): ?string
    {
        if (! $telephone) {
            return null;
        }

        return 'https://wa.me/'.preg_replace('/\D/', '', normaliser_telephone($telephone));
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

if (! function_exists('resoudre_periode')) {
    /**
     * Bornes de dates à partir de ?periode= (ou ?date_debut=/?date_fin=
     * explicites) — extrait de EspaceController::resoudrePeriode() pour être
     * réutilisé par MoiController::activites() ("Mes activités", Espace
     * Coordinateur) sans dupliquer la logique.
     *
     * @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon}
     */
    function resoudre_periode(\Illuminate\Http\Request $request): array
    {
        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            return [
                \Illuminate\Support\Carbon::parse($request->string('date_debut'))->startOfDay(),
                \Illuminate\Support\Carbon::parse($request->string('date_fin'))->endOfDay(),
            ];
        }

        return match ($request->string('periode')->toString() ?: 'aujourd_hui') {
            'tout' => [null, null],
            'semaine' => [now()->startOfWeek(), now()->endOfWeek()],
            'semaine_derniere' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'mois' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }
}
