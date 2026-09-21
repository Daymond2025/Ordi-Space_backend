<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LienAffilie;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vitrine;
use App\Services\CommandePubliqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Page d'arrivée de l'acheteur (dossier page_commande) : consultation d'un
 * lien de vente ou d'une vitrine et passage de commande, SANS compte. Public
 * par nature — n'expose que ce qu'un acheteur voit (fiche produit, prénom et
 * contact du conseiller) et jamais la commission ni les données internes.
 *
 * Deux entrées, un seul parcours de commande :
 *  - "lien"    : un produit précis (LienAffilie, /boutique/produit/{code}) ;
 *  - "vitrine" : la sélection complète d'un vendeur (Vitrine,
 *    /boutique/vitrine/{code}), ouverte par lien ou par le QR de l'affiche.
 */
class BoutiquePubliqueController extends Controller
{
    /** Fiche produit d'un lien de vente, avec le conseiller qui l'a partagé. */
    public function lien(string $code): JsonResponse
    {
        $lien = LienAffilie::where('code', $code)->with(['produit.images', 'produit.fraisLivraison.localite', 'livreur'])->firstOrFail();
        abort_unless($this->estCommandable($lien->produit), 404);

        return $this->success([
            'vendeur' => $this->formaterVendeur($lien->livreur),
            'produit' => $this->formaterProduit($lien->produit),
        ]);
    }

    /** La sélection d'un vendeur : ses produits ouverts à la revente, en stock. */
    public function vitrine(string $code): JsonResponse
    {
        $vitrine = Vitrine::where('code', $code)->with('user')->firstOrFail();

        $produits = $this->selectionVitrine()
            ->with('images')
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (Produit $produit) => [
                'id' => $produit->id,
                'nom_produit' => $produit->nom_produit,
                'image' => $produit->images->first()?->url_image,
                'prix_vente' => (float) $produit->prix_vente,
                'prix_barre' => $produit->prix_barre !== null ? (float) $produit->prix_barre : null,
                'pourcentage_reduction' => $produit->pourcentage_reduction,
                'etat_produit' => $produit->etat_produit,
                'specs' => array_values(array_filter([$produit->processeur, $produit->memoire_ram, $produit->stockage])),
            ]);

        return $this->success(['vendeur' => $this->formaterVendeur($vitrine->user), 'produits' => $produits]);
    }

    /** Fiche d'un produit de la sélection d'une vitrine. */
    public function produitVitrine(string $code, Produit $produit): JsonResponse
    {
        $vitrine = Vitrine::where('code', $code)->with('user')->firstOrFail();
        abort_unless($this->selectionVitrine()->whereKey($produit->id)->exists(), 404);

        $produit->load(['images', 'fraisLivraison.localite']);

        return $this->success(['vendeur' => $this->formaterVendeur($vitrine->user), 'produit' => $this->formaterProduit($produit)]);
    }

    /**
     * Enregistre une ouverture de lien (visite) — appelée une seule fois par
     * l'acheteur depuis son navigateur, donc les robots d'aperçu (WhatsApp,
     * réseaux) qui ne lancent pas de JavaScript ne gonflent pas les compteurs.
     */
    public function vueLien(string $code): JsonResponse
    {
        $lien = LienAffilie::where('code', $code)->firstOrFail();
        $lien->increment('vues');
        $lien->update(['derniere_activite_le' => now()]);

        return $this->success(['vues' => $lien->vues]);
    }

    /** Idem pour une vitrine : `src=qr` (adresse encodée dans le QR de l'affiche) compte comme un scan, sinon un clic. */
    public function vueVitrine(Request $request, string $code): JsonResponse
    {
        $vitrine = Vitrine::where('code', $code)->firstOrFail();
        $vitrine->increment($request->query('src') === 'qr' ? 'scans' : 'clics');

        return $this->success(['clics' => $vitrine->clics, 'scans' => $vitrine->scans]);
    }

    /** Passe la commande — voir CommandePubliqueService. Limitée par IP au niveau de la route. */
    public function commander(Request $request, CommandePubliqueService $service): JsonResponse
    {
        $data = $request->validate([
            'origine' => ['required', Rule::in(['lien', 'vitrine'])],
            'code' => ['required', 'string', 'max:20'],
            'produit_id' => ['required_if:origine,vitrine', 'nullable', 'integer'],
            'src' => ['nullable', Rule::in(['qr'])],
            'quantite' => ['required', 'integer', 'min:1', 'max:5'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'telephone' => ['required', 'string', 'max:30', function (string $attribut, mixed $valeur, \Closure $echec) {
                if (strlen(preg_replace('/\D/', '', (string) $valeur)) < 8) {
                    $echec('Le numéro de téléphone doit contenir au moins 8 chiffres.');
                }
            }],
            'localite_id' => ['required', 'integer', 'exists:localites,id'],
            'adresse' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['origine'] === 'lien') {
            $lien = LienAffilie::where('code', $data['code'])->with('produit')->firstOrFail();
            $vendeur = User::findOrFail($lien->livreur_id);
            $produit = $lien->produit;
            $source = 'whatsapp';
        } else {
            $vitrine = Vitrine::where('code', $data['code'])->firstOrFail();
            $vendeur = User::findOrFail($vitrine->user_id);
            $produit = $this->selectionVitrine()->find($data['produit_id']);
            if (! $produit) {
                throw ValidationException::withMessages(['produit_id' => ["Ce produit n'est plus proposé par ce vendeur."]]);
            }
            $lien = null;
            $source = ($data['src'] ?? null) === 'qr' ? 'qr' : 'whatsapp';
        }

        $commande = $service->creer($vendeur, $produit, $data['quantite'], $data, $source, $lien);

        return $this->success([
            'reference' => $commande->id,
            'nom_produit' => $produit->nom_produit,
            'quantite' => $data['quantite'],
            'montant_produits' => (float) $commande->montant_total,
            'frais_livraison' => (float) $commande->frais_livraison,
            'total_a_payer' => $commande->montantNet(),
            'vendeur' => $this->formaterVendeur($vendeur),
        ], status: 201);
    }

    /** Produits proposables à la vente par vitrine : publiés, en stock et ouverts à la revente (commission renseignée). */
    private function selectionVitrine()
    {
        return Produit::where('statut_produit', STATUT_PRODUIT_VALIDE)
            ->where('quantite_stock', '>', 0)
            ->whereNotNull('commission_revente')
            ->where('type_livraison', TYPE_LIVRAISON_PHYSIQUE);
    }

    private function estCommandable(Produit $produit): bool
    {
        return $produit->estVisibleALaVente() && ! $produit->estNumerique();
    }

    /** Ce que l'acheteur voit du conseiller : prénom, initiale, photo et moyens de le contacter. */
    private function formaterVendeur(User $vendeur): array
    {
        return [
            'nom' => trim(($vendeur->prenom ?? '').' '.($vendeur->nom ? mb_substr($vendeur->nom, 0, 1).'.' : '')),
            'photo' => $vendeur->photo,
            'telephone' => $vendeur->telephone,
            'whatsapp_url' => lien_whatsapp($vendeur->telephone),
        ];
    }

    private function formaterProduit(Produit $produit): array
    {
        return [
            'id' => $produit->id,
            'nom_produit' => $produit->nom_produit,
            'description' => $produit->description,
            'prix_vente' => (float) ($produit->prix_vente ?? $produit->prix),
            'prix_barre' => $produit->prix_barre !== null ? (float) $produit->prix_barre : null,
            'pourcentage_reduction' => $produit->pourcentage_reduction,
            'etat_produit' => $produit->etat_produit,
            'quantite_stock' => $produit->quantite_stock,
            'duree_garantie_mois' => $produit->duree_garantie_mois,
            'processeur' => $produit->processeur,
            'memoire_ram' => $produit->memoire_ram,
            'stockage' => $produit->stockage,
            'taille' => $produit->taille,
            'systeme_exploitation' => $produit->systeme_exploitation,
            'carte_graphique' => $produit->carte_graphique,
            'couleur' => $produit->couleur,
            'cadeaux' => $produit->cadeaux,
            'images' => $produit->images->pluck('url_image')->values(),
            'frais_livraison' => $produit->fraisLivraison
                ->map(fn ($f) => ['localite_id' => $f->localite_id, 'localite' => $f->localite?->nom, 'montant' => (float) $f->montant])
                ->sortBy('localite')
                ->values(),
        ];
    }
}
