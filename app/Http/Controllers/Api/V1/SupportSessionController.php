<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportSession;
use App\Services\Console\SupportSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La boutique met fin à l'accès du support de la plateforme.
 */
class SupportSessionController extends Controller
{
    public function __invoke(Request $request, int $session, SupportSessions $sessions): JsonResponse
    {
        $sessions->end(SupportSession::findOrFail($session), $request->user());

        return response()->json(['message' => 'Accès du support terminé.']);
    }
}
