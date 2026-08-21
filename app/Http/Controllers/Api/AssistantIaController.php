<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageAssistantIa;
use App\Services\AssistantIaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantIaController extends Controller
{
    public function __construct(private readonly AssistantIaService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $messages = MessageAssistantIa::where('client_id', $request->user()->id)
            ->orderBy('date_envoi')
            ->get();

        return $this->success($messages);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $client = $request->user()->client;

        $messageClient = MessageAssistantIa::create([
            'client_id' => $client->user_id,
            'role' => ROLE_MESSAGE_IA_CLIENT,
            'contenu' => $data['message'],
            'date_envoi' => now(),
        ]);

        $texteReponse = $this->service->repondre($client, $data['message']);

        $messageAssistant = MessageAssistantIa::create([
            'client_id' => $client->user_id,
            'role' => ROLE_MESSAGE_IA_ASSISTANT,
            'contenu' => $texteReponse,
            'date_envoi' => now(),
        ]);

        return $this->success([
            'message_client' => $messageClient,
            'message_assistant' => $messageAssistant,
        ], status: 201);
    }
}
