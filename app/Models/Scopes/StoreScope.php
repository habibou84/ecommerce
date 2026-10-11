<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limite automatiquement les requêtes à la boutique de l'adresse ({slug}.jibiat-marchand.com ou domaine
 * personnalisé). Hors boutique (console, files d'attente, commandes artisan), aucun filtre : ces contextes
 * filtrent explicitement avec forStore().
 */
class StoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $store = app(Tenancy::class)->store();

        if ($store !== null) {
            $builder->where($model->qualifyColumn('store_id'), $store->id);
        }
    }
}
