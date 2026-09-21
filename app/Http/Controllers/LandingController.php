<?php

namespace App\Http\Controllers;

use App\Models\Parametre;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;

/**
 * Page d'accueil publique d'Ordi'Space (route "/") : présente l'écosystème et
 * envoie chaque visiteur vers la plateforme qui le concerne. Conçue pour être
 * partagée en prospection (aperçu de lien WhatsApp/réseaux, un seul lien pour
 * tous les profils). Les adresses viennent de config/plateformes.php ; une
 * plateforme sans adresse est affichée « bientôt disponible ». Seuls les
 * espaces ouverts au public y figurent : les applications internes
 * (Coordinateur, Administration) ne sont volontairement pas présentées.
 */
class LandingController extends Controller
{
    public function __invoke(): View
    {
        $groupes = [
            'acheter' => [
                'titre' => 'Je veux acheter',
                'description' => 'Ordinateurs neufs, quasi neufs ou reconditionnés, livrés chez vous.',
                'plateformes' => ['client'],
            ],
            'travailler' => [
                'titre' => 'Je veux travailler avec Ordi\'Space',
                'description' => 'Livrez, vendez ou publiez : chaque métier a son espace dédié.',
                'plateformes' => ['livreur', 'fournisseur', 'commercial'],
            ],
        ];

        $definitions = $this->definitions();

        foreach ($groupes as &$groupe) {
            $groupe['plateformes'] = array_map(
                fn (string $cle) => $definitions[$cle] + ['cle' => $cle, 'url' => config("plateformes.{$cle}") ?: null],
                $groupe['plateformes']
            );
        }
        unset($groupe);

        return view('landing', [
            'groupes' => $groupes,
            // Le numéro du support est réglé par l'Admin : sans lui, la page n'affiche simplement aucun contact.
            'whatsappSupport' => lien_whatsapp($this->numeroSupport()),
        ]);
    }

    /**
     * Cette page est la vitrine publique du projet : elle doit s'afficher même si
     * la base de données est momentanément indisponible ou pas encore migrée —
     * seul le contact WhatsApp disparaît alors.
     */
    private function numeroSupport(): ?string
    {
        try {
            return Parametre::lire(PARAMETRE_SUPPORT_TELEPHONE);
        } catch (QueryException) {
            return null;
        }
    }

    /** @return array<string, array{nom: string, accroche: string, points: list<string>, icone: string}> */
    private function definitions(): array
    {
        return [
            'client' => [
                'nom' => 'Espace Client',
                'accroche' => 'Achetez vos ordinateurs et accessoires, suivez vos commandes.',
                'points' => [
                    'Ordinateurs neufs, quasi neufs et reconditionnés',
                    'Livraison à domicile, paiement à la réception',
                    'Garanties, SAV et assistance',
                ],
                'icone' => 'client',
            ],
            'livreur' => [
                'nom' => 'Espace Livreur',
                'accroche' => 'Livrez, encaissez et gagnez des commissions.',
                'points' => [
                    'Missions et itinéraires de livraison',
                    'Boutique : revendez avec votre lien, votre QR et votre affiche',
                    'Portefeuille de commissions, retraits Mobile Money',
                ],
                'icone' => 'livreur',
            ],
            'fournisseur' => [
                'nom' => 'Espace Fournisseur',
                'accroche' => 'Publiez vos produits et suivez vos ventes.',
                'points' => [
                    'Catalogue et tarifs de livraison par commune',
                    'Suivi des commandes et des livraisons',
                    'Portefeuille et règlements',
                ],
                'icone' => 'fournisseur',
            ],
            'commercial' => [
                'nom' => 'Espace Commercial',
                'accroche' => 'Enregistrez les ventes de vos clients.',
                'points' => [
                    'Commandes prises pour le compte de vos clients',
                    'Canaux WhatsApp, Facebook, Instagram et TikTok',
                    'Suivi de chaque commande jusqu\'à la livraison',
                ],
                'icone' => 'commercial',
            ],
        ];
    }
}
