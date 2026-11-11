<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ClientRapideController;
use App\Http\Controllers\Api\CommandeController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commande\StoreCommandeRequest;
use App\Models\Commande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Écran Admin "Nouveau client" (Enregistrer la commande) : client + adresse
 * + commande + livreur en UNE seule opération. Avant, l'écran enchaînait 4
 * appels : si la commande était refusée (stock, frais de livraison…), le
 * message "données invalides" s'affichait alors que le client et son
 * adresse étaient déjà enregistrés. Ici tout est dans une transaction : à
 * la moindre erreur, rien n'est conservé. Les étapes réutilisent les
 * contrôleurs existants (mêmes règles, mêmes calculs), sans les dupliquer.
 */
class NouvelleCommandeClientController extends Controller
{
    public function store(Request $request, ClientRapideController $clients, CommandeController $commandes): JsonResponse
    {
        $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'telephone' => ['required', 'string', 'max:30'],
            'localite_id' => ['required', 'integer', 'exists:localites,id'],
            'livreur_id' => ['required', 'integer', 'exists:livreurs,user_id'],
            'commande' => ['required', 'array'],
        ]);

        DB::beginTransaction();

        try {
            $client = $clients->store($this->sousRequete($request, $request->only(['nom', 'prenom', 'telephone'])))->getData(true)['data'];
            $adresse = $clients->creerAdresse($this->sousRequete($request, ['localite_id' => $request->integer('localite_id')]), $client['id'])->getData(true)['data'];

            $requeteCommande = StoreCommandeRequest::createFrom($this->sousRequete($request, [
                ...$request->input('commande'),
                'client_id' => $client['id'],
                'adresse_id' => $adresse['id'],
            ]));
            $requeteCommande->setContainer(app())->setRedirector(app('redirect'));
            $requeteCommande->validateResolved();
            $commande = $commandes->store($requeteCommande)->getData(true)['data'];

            $commandes->assignerLivreur(
                $this->sousRequete($request, ['livreur_id' => $request->integer('livreur_id')]),
                Commande::findOrFail($commande['id']),
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->success(['client_id' => $client['id'], 'commande_id' => $commande['id']], status: 201);
    }

    /** Requête interne portant les champs d'une étape, au nom de l'admin connecté. */
    private function sousRequete(Request $origine, array $donnees): Request
    {
        $requete = Request::create($origine->url(), 'POST', $donnees);
        $requete->headers->set('Accept', 'application/json');
        $requete->setUserResolver(fn () => $origine->user());

        return $requete;
    }
}
