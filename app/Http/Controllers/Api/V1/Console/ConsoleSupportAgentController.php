<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Services\Support\SupportAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jibiat\Socle\PhoneNumber;

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
            'test_phones' => ['nullable', 'array', 'max:10'],
            'test_phones.*' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail) {
                if (PhoneNumber::normalize((string) $value) === null) {
                    $fail('« '.$value.' » n\'est pas un numéro de téléphone.');
                }
            }],
        ], [], ['daily_budget' => 'plafond par jour', 'test_phones' => 'numéros d\'essai']);
        $this->agent->update($data['enabled'], (float) $data['daily_budget'], $data['test_phones'] ?? []);
        AdminAction::create(['admin_id' => $request->user()->id, 'action' => 'support_agent_settings', 'data' => $data, 'ip' => $request->ip()]);

        return $this->show();
    }
}
