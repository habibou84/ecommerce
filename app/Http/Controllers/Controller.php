<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Support\Tenancy;

abstract class Controller
{
    /**
     * Boutique de l'adresse (routes réservées à la zone « store »).
     */
    protected function currentStore(): Store
    {
        return app(Tenancy::class)->store() ?? abort(404);
    }
}
