<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Localite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocaliteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Localite::orderBy('nom');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return $this->success($query->get());
    }
}
