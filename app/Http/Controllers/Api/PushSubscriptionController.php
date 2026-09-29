<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Abonnements Web Push — voir App\Services\PushNotificationService pour
 * l'envoi. Un compte peut avoir plusieurs abonnements (plusieurs
 * appareils/navigateurs) : store()/destroy() n'agissent jamais que sur
 * l'abonnement précis de l'appel (endpoint), jamais tous d'un coup.
 */
class PushSubscriptionController extends Controller
{
    /** Clé publique VAPID — le front en a besoin pour pushManager.subscribe(). */
    public function clePublique(): JsonResponse
    {
        return $this->success(['cle_publique' => config('services.vapid.public_key')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $abonnement = PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
            ]
        );

        return $this->success($abonnement, status: 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return $this->success(['message' => 'Abonnement supprimé.']);
    }
}
