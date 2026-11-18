<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Services\Support\SupportAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console › Centre d'aide › Assistant WhatsApp : activer l'assistant du support, son plafond de coût par jour, et
 * le suivi (réponses données seul, passages à l'équipe, coût).
 */
class ConsoleSupportAgentController extends Controller
{
    public function __construct(private readonly SupportAgent $agent) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => ['settings' => $this->agent->settings(), 'stats' => $this->agent->stats()]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'daily_budget' => ['required', 'numeric', 'min:0.5', 'max:100'],
        ], [], ['daily_budget' => 'plafond par jour']);
        $this->agent->update($data['enabled'], (float) $data['daily_budget']);
        AdminAction::create(['admin_id' => $request->user()->id, 'action' => 'support_agent_settings', 'data' => $data, 'ip' => $request->ip()]);

        return $this->show();
    }
}
