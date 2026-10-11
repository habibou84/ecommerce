<?php

use App\Http\Controllers\HandoffController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

// Racine de chaque adresse : présentation, site de la boutique, console (voir Tenancy)
Route::get('/', [PageController::class, 'home'])->name('home');

// jibiat-marchand.com : création de boutique et connexion (application Vue)
Route::middleware('zone:platform')->group(function () {
    Route::get('/creer-ma-boutique', [PageController::class, 'spa'])->name('signup');
    Route::get('/connexion', [PageController::class, 'spa'])->name('platform.login');
    Route::get('/mot-de-passe-oublie', [PageController::class, 'spa']);
});

// {slug}.jibiat-marchand.com : gestion de la boutique (application Vue)
Route::middleware('zone:store')->group(function () {
    Route::get('/auth/relais', HandoffController::class)->name('handoff');
    Route::get('/invitation/{token}', [PageController::class, 'spa'])->name('invitation');
    Route::get('/gestion/{any?}', [PageController::class, 'spa'])->where('any', '.*')->name('gestion');
});
