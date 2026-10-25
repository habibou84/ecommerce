<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use App\Notifications\ConsoleTeamChanged;
use App\Services\Billing\BillingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;
use Throwable;

/**
 * Comptes de la console et sécurité de l'accès :
 *  - équipe : les administrateurs, l'ajout et le retrait d'un administrateur. Chaque changement demande le mot de passe
 *    de la personne connectée, est journalisé et prévenu par e-mail à toute l'équipe ; on ne se retire pas soi-même et
 *    il reste toujours au moins un administrateur. Un nouvel administrateur n'a pas de mot de passe connu : il le
 *    choisit avec « Mot de passe oublié » (preuve de son numéro), puis se connecte avec le code reçu par e-mail ;
 *  - mes sessions : les appareils connectés à mon compte, que je peux fermer ;
 *  - recherche d'un utilisateur de la plateforme (lecture seule), pour le support.
 */
class ConsoleTeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $admins = User::where('is_super_admin', true)->orderBy('name')->get();

        return response()->json(['data' => $admins->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'phone' => PhoneNumber::display($u->phone), 'email' => $u->email,
            'active' => $u->isActive(), 'last_login_at' => $u->last_login_at?->toIso8601String(), 'me' => $u->id === $request->user()->id,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            // Adresse obligatoire : le code de connexion y est envoyé
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'current_password' => ['required', 'string', 'max:100'],
        ], [], ['name' => 'nom', 'phone' => 'numéro', 'email' => 'adresse e-mail', 'current_password' => 'votre mot de passe']);
        $this->confirmPassword($request);

        $phone = PhoneNumber::normalize($data['phone']) ?? throw ValidationException::withMessages(['phone' => 'Numéro invalide.']);
        // Un compte de la console est un compte à part : pas de promotion d'un compte marchand existant
        if (User::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'Ce numéro a déjà un compte Jibiat Marchand. Utilisez un autre numéro pour la console.']);
        }

        $admin = DB::transaction(function () use ($data, $phone) {
            $admin = User::create([
                'name' => $data['name'], 'phone' => $phone, 'email' => $data['email'],
                // Mot de passe inconnu de tous : la personne choisit le sien avec « Mot de passe oublié »
                'password' => Hash::make(Str::random(64)), 'status' => UserStatus::Active, 'phone_verified_at' => null,
            ]);
            $admin->forceFill(['is_super_admin' => true])->save();

            return $admin;
        });
        $this->record($request, 'console_admin_added', ['admin' => $admin->name, 'phone' => PhoneNumber::display($phone)]);
        $this->tell("{$request->user()->name} a ajouté {$admin->name} (".PhoneNumber::display($phone).') à l\'équipe de la console.', $request);

        return $this->index($request);
    }

    public function revoke(Request $request, int $admin): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'max:100']], [], ['current_password' => 'votre mot de passe']);
        $this->confirmPassword($request);
        $target = User::where('is_super_admin', true)->findOrFail($admin);
        if ($target->id === $request->user()->id) {
            throw ValidationException::withMessages(['admin' => 'Vous ne pouvez pas vous retirer vous-même.']);
        }
        if (User::where('is_super_admin', true)->whereKeyNot($target->id)->where('status', UserStatus::Active->value)->doesntExist()) {
            throw ValidationException::withMessages(['admin' => 'Il doit rester au moins un administrateur.']);
        }

        $target->forceFill(['is_super_admin' => false])->save();
        // Ses sessions ouvertes se ferment tout de suite
        DB::table('sessions')->where('user_id', $target->id)->delete();
        $this->record($request, 'console_admin_removed', ['admin' => $target->name]);
        $this->tell("{$request->user()->name} a retiré {$target->name} de l'équipe de la console.", $request);

        return $this->index($request);
    }

    /**
     * Mes sessions ouvertes (téléphone, ordinateur…), la plus récente d'abord.
     */
    public function sessions(Request $request): JsonResponse
    {
        $current = $request->session()->getId();
        $rows = DB::table('sessions')->where('user_id', $request->user()->id)->orderByDesc('last_activity')->limit(30)->get();

        return response()->json(['data' => $rows->map(fn ($s) => [
            // Identifiant affiché sous forme d'empreinte : l'identifiant de session reste secret
            'id' => hash('sha256', $s->id), 'ip' => $s->ip_address, 'device' => mb_substr((string) $s->user_agent, 0, 190),
            'last_activity' => now()->setTimestamp((int) $s->last_activity)->toIso8601String(), 'current' => hash_equals($current, $s->id),
        ])]);
    }

    /**
     * Ferme une de mes sessions (empreinte) ou toutes les autres (« others »).
     */
    public function closeSession(Request $request, string $session): JsonResponse
    {
        $current = $request->session()->getId();
        $ids = DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $current)->pluck('id')
            ->filter(fn (string $id) => $session === 'others' || hash_equals(hash('sha256', $id), $session));
        DB::table('sessions')->whereIn('id', $ids->all())->delete();
        $this->record($request, 'console_sessions_closed', ['count' => $ids->count()]);

        return $this->sessions($request);
    }

    /**
     * Recherche d'un utilisateur (numéro, nom ou e-mail) : ses espaces marchands et son rôle de propriétaire.
     */
    public function users(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 3) {
            return response()->json(['data' => []]);
        }
        $phone = PhoneNumber::normalize($q);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)).'%';
        $users = User::query()->with('stores:id,name,slug,owner_id')
            ->where(fn ($w) => $w->when($phone, fn ($w) => $w->orWhere('phone', $phone))
                ->orWhereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]))
            ->limit(20)->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'phone' => PhoneNumber::display($u->phone), 'email' => $u->email,
            'active' => $u->isActive(), 'super_admin' => (bool) $u->is_super_admin, 'last_login_at' => $u->last_login_at?->toIso8601String(),
            'stores' => $u->stores->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'owner' => $s->owner_id === $u->id])->values(),
        ])]);
    }

    private function confirmPassword(Request $request): void
    {
        if (! Hash::check((string) $request->input('current_password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mot de passe incorrect.']);
        }
    }

    private function record(Request $request, string $action, array $data): void
    {
        AdminAction::create(['admin_id' => $request->user()->id, 'action' => $action, 'data' => $data, 'ip' => $request->ip()]);
    }

    /**
     * E-mail à toute l'équipe : un changement d'équipe ne passe jamais inaperçu.
     */
    private function tell(string $what, Request $request): void
    {
        $mailer = app(BillingSettings::class)->mailer();
        $emails = User::where('is_super_admin', true)->whereNotNull('email')->pluck('email');
        if ($mailer === null || $emails->isEmpty()) {
            return;
        }
        try {
            Notification::route('mail', $emails->all())->notifyNow(new ConsoleTeamChanged($what, $request->ip(), $mailer['mailer'], $mailer['from']));
        } catch (Throwable $e) {
            Log::warning('Alerte de changement d\'équipe de la console non envoyée', ['error' => $e->getMessage()]);
        }
    }
}
