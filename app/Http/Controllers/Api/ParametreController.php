<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réglages globaux exposés aux apps. Pour l'instant : le support Ordi'Space
 * ("Support Partenaire" de l'écran "Mes infos" du livreur) — un numéro
 * unique, le même pour tous, fixé par l'Administrateur.
 */
class ParametreController extends Controller
{
    /** Lisible par tout utilisateur connecté. `telephone` est null tant que l'Admin ne l'a pas renseigné. */
    public function support(): JsonResponse
    {
        return $this->success($this->formaterSupport());
    }

    /** Administrateur uniquement (route sous le préfixe admin). */
    public function modifierSupport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30', function (string $attribut, mixed $valeur, \Closure $echec) {
                // Espaces, points et tirets sont tolérés ; il faut au moins 8 chiffres.
                if (strlen(preg_replace('/\D/', '', (string) $valeur)) < 8) {
                    $echec('Le numéro du support doit contenir au moins 8 chiffres.');
                }
            }],
        ]);

        Parametre::ecrire(PARAMETRE_SUPPORT_TELEPHONE, normaliser_numero_ci($data['telephone']));

        return $this->success($this->formaterSupport());
    }

    private function formaterSupport(): array
    {
        $telephone = Parametre::lire(PARAMETRE_SUPPORT_TELEPHONE);

        return [
            'nom' => "Support Ordi'Space",
            'telephone' => $telephone,
            'whatsapp_url' => lien_whatsapp($telephone),
        ];
    }
}
