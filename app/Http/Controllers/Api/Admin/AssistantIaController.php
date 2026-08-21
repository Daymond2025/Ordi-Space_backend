<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MessageAssistantIa;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vue admin des conversations avec l'agent IA "Ellah" (page Aide rapide côté
 * client) — supervision/audit des échanges, aucune action possible ici.
 */
class AssistantIaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Client::query()
            ->join('users', 'users.id', '=', 'clients.user_id')
            ->where('users.type_utilisateur', ROLE_CLIENT)
            ->whereExists(fn ($q) => $q->selectRaw(1)
                ->from('messages_assistant_ia')
                ->whereColumn('messages_assistant_ia.client_id', 'clients.user_id'))
            ->select(['clients.user_id as id', 'users.nom', 'users.prenom', 'users.email'])
            ->selectSub(
                MessageAssistantIa::query()->selectRaw('COUNT(*)')->whereColumn('client_id', 'clients.user_id'),
                'nombre_messages'
            )
            ->selectSub(
                MessageAssistantIa::query()->selectRaw('MAX(date_envoi)')->whereColumn('client_id', 'clients.user_id'),
                'dernier_message_date'
            )
            ->selectSub(
                MessageAssistantIa::query()
                    ->select('contenu')
                    ->whereColumn('client_id', 'clients.user_id')
                    ->orderByDesc('date_envoi')
                    ->limit(1),
                'dernier_message_contenu'
            );

        if ($request->filled('recherche')) {
            $terme = '%'.$request->string('recherche').'%';
            $query->where(fn ($q) => $q
                ->where('users.nom', 'like', $terme)
                ->orWhere('users.prenom', 'like', $terme)
                ->orWhere('users.email', 'like', $terme));
        }

        $clients = $query->orderByDesc('dernier_message_date')->paginate(paginate_per_page($request));

        $clients->getCollection()->transform(function ($c) {
            $c->nombre_messages = (int) $c->nombre_messages;

            return $c;
        });

        return $this->success($clients);
    }

    public function messages(User $utilisateur): JsonResponse
    {
        abort_unless($utilisateur->type_utilisateur === ROLE_CLIENT, 404);

        $messages = MessageAssistantIa::where('client_id', $utilisateur->id)
            ->orderBy('date_envoi')
            ->get();

        return $this->success([
            'client' => [
                'id' => $utilisateur->id,
                'nom' => $utilisateur->nom,
                'prenom' => $utilisateur->prenom,
                'email' => $utilisateur->email,
            ],
            'messages' => $messages,
        ]);
    }
}
