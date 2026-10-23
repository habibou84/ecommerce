<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Models\WhatsappLink;
use App\Services\WhatsApp\WhatsAppBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;
use RuntimeException;

/**
 * Réglages › WhatsApp et boîte Messages › Relier WhatsApp : le QR code à scanner depuis « Appareils connectés » sur le
 * téléphone de la boutique, ou le code à saisir avec le numéro, l'état de la connexion, la déconnexion. Les conversations arrivent ensuite dans la boîte Messages.
 */
class WhatsAppLinkController extends Controller
{
    public function __construct(private readonly WhatsAppBridge $bridge) {}

    public function show(): JsonResponse
    {
        $link = WhatsappLink::first();
        $live = $link ? $this->live($link) : null;
        if ($link && $live) {
            $this->sync($link, $live);
        }

        return response()->json(['data' => $this->describe($link, $live)]);
    }

    /**
     * L'état gardé ici suit les événements du service ; s'il en a manqué un (mise à jour du serveur pendant la
     * liaison, coupure), l'état réel du service fait foi : relié, ou session perdue à relier de nouveau.
     *
     * @param  array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}  $live
     */
    private function sync(WhatsappLink $link, array $live): void
    {
        if ($live['status'] === 'connected' && ! $link->isConnected()) {
            $link->forceFill(['status' => 'connected', 'phone' => $live['phone'] ?: $link->phone, 'name' => $live['name'] ?: $link->name, 'connected_at' => now(), 'last_error' => null])->save();
        } elseif ($live['status'] === 'logged_out' && $link->isConnected()) {
            $link->forceFill(['status' => 'disconnected', 'last_error' => 'La liaison avec WhatsApp a été perdue : reliez de nouveau votre numéro.'])->save();
        }
    }

    /** Démarre la session et renvoie le QR code (ou l'état s'il est déjà relié) */
    public function start(): JsonResponse
    {
        $link = WhatsappLink::firstOrNew(['store_id' => $this->currentStore()->id]);
        if (! $link->isConnected()) {
            $link->forceFill(['status' => 'pending', 'last_error' => null])->save();
        }
        try {
            $live = $this->bridge->start($link);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['link' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->describe($link->fresh(), $live)]);
    }

    /** Liaison avec le numéro : code à saisir sur le téléphone (« Connecter avec le numéro de téléphone à la place ») */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']], [], ['phone' => 'numéro WhatsApp']);
        $phone = PhoneNumber::normalize($data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Numéro WhatsApp invalide.']);
        }
        $link = WhatsappLink::firstOrNew(['store_id' => $this->currentStore()->id]);
        if (! $link->isConnected()) {
            $link->forceFill(['status' => 'pending', 'last_error' => null])->save();
        }
        try {
            $live = $this->bridge->pair($link, $phone);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->describe($link->fresh(), $live)]);
    }

    public function destroy(): JsonResponse
    {
        $link = WhatsappLink::first();
        if ($link !== null) {
            try {
                $this->bridge->logout($link);
            } catch (RuntimeException) {
                // Service injoignable : la boutique est tout de même déconnectée de son côté
            }
            $link->delete();
        }

        return response()->json(['data' => $this->describe(null, null)]);
    }

    /**
     * État vu par le service (QR code en cours) ; null s'il est injoignable.
     *
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}|null
     */
    private function live(WhatsappLink $link): ?array
    {
        try {
            return $this->bridge->status($link);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}|null  $live
     * @return array<string, mixed>
     */
    private function describe(?WhatsappLink $link, ?array $live): array
    {
        return [
            'available' => WhatsAppBridge::configured(),
            // none : pas relié ; pending : QR code à scanner ; connected : relié ; disconnected : à relier de nouveau
            'status' => $link?->status ?? 'none',
            'qr' => $link?->isConnected() ? null : ($live['qr'] ?? null),
            'code' => $link?->isConnected() ? null : ($live['code'] ?? null),
            // Numéro proposé pour la liaison par code : celui de la boutique
            'store_phone' => $this->currentStore()->whatsapp_phone ? PhoneNumber::display($this->currentStore()->whatsapp_phone) : null,
            'phone' => $link?->phone,
            'name' => $link?->name,
            'connected_at' => $link?->connected_at?->toIso8601String(),
            'last_error' => $link?->last_error,
        ];
    }
}
