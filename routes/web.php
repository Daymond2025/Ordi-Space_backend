<?php

use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

// Page d'accueil publique d'Ordi'Space : présentation + accès à chaque plateforme.
Route::get('/', LandingController::class);
