<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Enveloppe de succès standard — voir étude d'architecture, section
     * "Gestion des erreurs" (les erreurs, elles, passent par bootstrap/app.php).
     */
    protected function success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['success' => true, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }
}
