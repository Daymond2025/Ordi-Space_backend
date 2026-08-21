<?php

namespace App\Services;

use App\Models\Client;
use App\Models\LigneCommande;
use App\Models\MessageAssistantIa;
use App\Models\QuestionFrequente;
use App\Models\Reclamation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Agent IA "Ellah" (page Aide rapide) — appelle l'API Claude avec du tool use
 * pour aller chercher les données réelles du client (achats, commandes,
 * réclamations) au lieu de laisser le modèle deviner ou halluciner. Chaque
 * outil est exécuté côté serveur, strictement scopé au client authentifié :
 * l'IA n'a jamais un accès plus large que ce que l'API autorise déjà.
 */
class AssistantIaService
{
    private const NOMBRE_TOURS_MAX = 4;
    private const HISTORIQUE_MESSAGES_MAX = 20;

    public function repondre(Client $client, string $messageUtilisateur): string
    {
        $messages = $this->construireHistorique($client)
            ->push(['role' => 'user', 'content' => $messageUtilisateur])
            ->all();

        for ($tour = 0; $tour < self::NOMBRE_TOURS_MAX; $tour++) {
            $reponse = $this->appellerClaude($messages);

            if (($reponse['stop_reason'] ?? null) !== 'tool_use') {
                return $this->extraireTexte($reponse) ?: "Désolé, je n'ai pas de réponse à te proposer pour le moment.";
            }

            $messages[] = ['role' => 'assistant', 'content' => $reponse['content']];

            $resultatsOutils = [];
            foreach ($reponse['content'] as $bloc) {
                if (($bloc['type'] ?? null) !== 'tool_use') {
                    continue;
                }

                $resultatsOutils[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $bloc['id'],
                    'content' => $this->executerOutil($client, $bloc['name'], $bloc['input'] ?? []),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $resultatsOutils];
        }

        return "Ta demande touche à plusieurs informations à la fois et je n'arrive pas à te répondre précisément. "
            ."Un conseiller pourra t'aider directement via le Centre d'aide.";
    }

    private function construireHistorique(Client $client)
    {
        return MessageAssistantIa::where('client_id', $client->user_id)
            ->latest('date_envoi')
            ->limit(self::HISTORIQUE_MESSAGES_MAX)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (MessageAssistantIa $message) => [
                'role' => $message->role === ROLE_MESSAGE_IA_CLIENT ? 'user' : 'assistant',
                'content' => $message->contenu,
            ]);
    }

    private function appellerClaude(array $messages): array
    {
        $cle = config('services.anthropic.key');

        if (! $cle) {
            Log::error("Assistant IA : ANTHROPIC_API_KEY n'est pas configurée.");
            throw new HttpException(503, "L'assistant n'est pas encore configuré. Réessaie plus tard.");
        }

        $reponse = Http::withHeaders([
            'x-api-key' => $cle,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 1024,
            'system' => $this->promptSysteme(),
            'messages' => $messages,
            'tools' => $this->outils(),
        ]);

        if ($reponse->failed()) {
            Log::error('Assistant IA : échec de l\'appel à Claude', [
                'status' => $reponse->status(),
                'body' => $reponse->body(),
            ]);
            throw new HttpException(503, "L'assistant est momentanément indisponible.");
        }

        return $reponse->json();
    }

    private function extraireTexte(array $reponse): string
    {
        return collect($reponse['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");
    }

    private function promptSysteme(): string
    {
        return <<<'PROMPT'
        Tu es Ellah, l'assistante virtuelle de l'application OrdiSpace (vente d'ordinateurs et
        d'accessoires, garanties, GarantiX, service après-vente et réclamations).

        Règles :
        - Réponds toujours en français, de façon chaleureuse, claire et concise (quelques phrases,
          pas de longs pavés sauf si le client demande un détail précis).
        - Avant de répondre à une question sur les achats, commandes ou réclamations du client,
          utilise l'outil correspondant pour consulter ses données réelles — ne devine jamais ces
          informations et ne les invente jamais.
        - Pour les questions générales sur OrdiSpace, consulte d'abord les questions fréquentes.
        - Tu ne peux voir que les données du client qui te parle actuellement, jamais celles d'un
          autre client.
        - Tu ne peux pas créer, modifier ou annuler quoi que ce soit (commande, réclamation,
          rendez-vous) : si le client en a besoin, invite-le à utiliser la fonctionnalité
          correspondante dans l'application (ex. "Mes réclamations", "Déclarer une panne") ou à
          contacter le service après-vente via le Centre d'aide.
        - Si la question sort totalement du cadre d'OrdiSpace, réponds brièvement puis recentre la
          conversation sur ce que tu peux faire.
        PROMPT;
    }

    private function outils(): array
    {
        $schemaVide = ['type' => 'object', 'properties' => new stdClass(), 'required' => []];

        return [
            [
                'name' => 'lister_mes_achats',
                'description' => 'Liste les ordinateurs/produits déjà livrés au client, avec le statut de leur garantie de base et d\'un éventuel abonnement GarantiX.',
                'input_schema' => $schemaVide,
            ],
            [
                'name' => 'lister_mes_commandes',
                'description' => 'Liste les commandes récentes du client (jusqu\'à 10) avec leur statut et leur montant.',
                'input_schema' => $schemaVide,
            ],
            [
                'name' => 'lister_mes_reclamations',
                'description' => 'Liste les réclamations déposées par le client, leur statut de traitement et la réponse d\'OrdiSpace le cas échéant.',
                'input_schema' => $schemaVide,
            ],
            [
                'name' => 'consulter_questions_frequentes',
                'description' => 'Consulte les questions fréquentes publiées par OrdiSpace (sujets généraux : utilisation, produits, services).',
                'input_schema' => $schemaVide,
            ],
        ];
    }

    private function executerOutil(Client $client, string $nom, array $input): string
    {
        return match ($nom) {
            'lister_mes_achats' => $this->outilListerAchats($client),
            'lister_mes_commandes' => $this->outilListerCommandes($client),
            'lister_mes_reclamations' => $this->outilListerReclamations($client),
            'consulter_questions_frequentes' => $this->outilQuestionsFrequentes(),
            default => "Cet outil n'existe pas.",
        };
    }

    private function outilListerAchats(Client $client): string
    {
        $lignes = LigneCommande::with(['produit', 'garantie', 'abonnementsGarantix.formule', 'commande'])
            ->whereHas('commande', fn ($q) => $q->where('client_id', $client->user_id))
            ->whereHas('garantie')
            ->latest('id')
            ->get();

        if ($lignes->isEmpty()) {
            return "Ce client n'a encore aucun achat livré enregistré.";
        }

        return $lignes->map(function (LigneCommande $ligne) {
            $garantieActive = $ligne->garantie && $ligne->garantie->date_fin->gte(now());
            $abonnementActif = $ligne->abonnementsGarantix->first(fn ($a) => $a->estActif());

            $texte = sprintf(
                '- %s (acheté le %s) — garantie de base %s',
                $ligne->produit->nom_produit,
                $ligne->commande->date_commande->format('d/m/Y'),
                $garantieActive
                    ? "active jusqu'au {$ligne->garantie->date_fin->format('d/m/Y')}"
                    : 'expirée'
            );

            if ($abonnementActif) {
                $texte .= sprintf(
                    ', abonnement GarantiX « %s » actif jusqu\'au %s',
                    $abonnementActif->formule->libelle_complet,
                    $abonnementActif->date_fin->format('d/m/Y')
                );
            }

            return $texte;
        })->implode("\n");
    }

    private function outilListerCommandes(Client $client): string
    {
        $commandes = $client->commandes()->latest('date_commande')->limit(10)->get();

        if ($commandes->isEmpty()) {
            return "Ce client n'a encore passé aucune commande.";
        }

        $libelles = [
            'en_attente' => 'En attente',
            'validee' => 'Validée',
            'en_preparation' => 'En préparation',
            'en_livraison' => 'En livraison',
            'livree' => 'Livrée',
            'annulee' => 'Annulée',
        ];

        return $commandes->map(fn ($commande) => sprintf(
            '- Commande #%d du %s — statut : %s — montant : %s CFA',
            $commande->id,
            $commande->date_commande->format('d/m/Y'),
            $libelles[$commande->statut_commande] ?? $commande->statut_commande,
            number_format((float) $commande->montant_total, 0, ',', ' ')
        ))->implode("\n");
    }

    private function outilListerReclamations(Client $client): string
    {
        $reclamations = $client->reclamations()->latest('date_reclamation')->limit(10)->get();

        if ($reclamations->isEmpty()) {
            return "Ce client n'a déposé aucune réclamation.";
        }

        $libelles = [
            'nouvelle' => 'Nouvelle',
            'en_cours' => 'En cours de traitement',
            'resolue' => 'Résolue',
            'rejetee' => 'Rejetée',
        ];

        return $reclamations->map(function (Reclamation $reclamation) use ($libelles) {
            $texte = sprintf(
                '- « %s » déposée le %s — statut : %s',
                $reclamation->sujet,
                $reclamation->date_reclamation->format('d/m/Y'),
                $libelles[$reclamation->statut] ?? $reclamation->statut
            );

            if ($reclamation->reponse_admin) {
                $texte .= " — réponse d'OrdiSpace : {$reclamation->reponse_admin}";
            }

            return $texte;
        })->implode("\n");
    }

    private function outilQuestionsFrequentes(): string
    {
        $questions = QuestionFrequente::where('statut', STATUT_QUESTION_PUBLIE)
            ->orderBy('ordre_affichage')
            ->get();

        if ($questions->isEmpty()) {
            return 'Aucune question fréquente publiée pour le moment.';
        }

        return $questions->map(function (QuestionFrequente $question) {
            $reponse = $question->reponse ?: '(réponse disponible en audio dans l\'application, pas de texte)';

            return "- Q : {$question->question}\n  R : {$reponse}";
        })->implode("\n");
    }
}
