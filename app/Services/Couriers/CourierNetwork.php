<?php

namespace App\Services\Couriers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\CourierProfile;
use App\Models\Place;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Models\User;
use App\Notifications\CourierNetworkNotice;
use App\Services\Accounts\StoreRoles;
use App\Services\Accounts\TeamNotifier;
use App\Services\Billing\PlanLimits;
use App\Support\Files;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Livreurs inscrits au lien commun et boutiques : une boutique demande à un livreur de livrer pour elle (par son
 * numéro ou depuis l'annuaire), il accepte ou refuse dans son application. Une boutique peut exiger la pièce
 * d'identité : sans elle, le livreur ne peut pas accepter sa demande.
 */
class CourierNetwork
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly StoreRoles $roles,
        private readonly TeamNotifier $team,
        private readonly SharedCouriers $shared,
    ) {}

    /** Profil du livreur (créé au besoin pour un livreur d'avant le lien commun, à partir de sa fiche) */
    public function profileFor(User $user): CourierProfile
    {
        return CourierProfile::firstOrCreate(['user_id' => $user->id], [
            'vehicle_type' => Courier::withoutGlobalScope(StoreScope::class)->where('user_id', $user->id)->latest('id')->value('vehicle_type') ?? 'moto',
        ]);
    }

    /** Livreur inscrit : profil, ou fiche de livreur dans une boutique (avant le lien commun) */
    public static function isCourier(User $user): bool
    {
        return CourierProfile::where('user_id', $user->id)->exists()
            || Courier::withoutGlobalScope(StoreScope::class)->where('user_id', $user->id)->whereIn('status', ['active', 'requested'])->exists();
    }

    /**
     * La boutique demande au livreur de livrer pour elle. Sa fiche est « demandée » : pas de course avant qu'il accepte.
     *
     * @param  array{pay_rule_id?: ?int, cash_limit?: ?int, place_ids?: list<int>, note?: ?string}  $data
     */
    public function request(Store $store, User $by, User $courier, array $data = []): Courier
    {
        if ($courier->activeMembershipIn($store) !== null && $this->roles->roleOf($courier, $store) !== Role::Courier) {
            throw new BusinessRuleException('Cette personne fait déjà partie de votre équipe : ajoutez-la comme livreur depuis sa fiche.', 'phone');
        }
        $existing = Courier::forStore($store->id)->where(fn ($q) => $q->where('user_id', $courier->id)->orWhere('phone', $courier->phone))->latest('id')->first();
        if ($existing !== null && in_array($existing->status, ['active', 'invited'], true)) {
            throw new BusinessRuleException('Ce livreur est déjà enregistré.', 'phone');
        }
        if ($existing?->status === 'requested') {
            throw new BusinessRuleException('Une demande est déjà en attente de sa réponse.', 'phone');
        }
        $this->limits->ensureCanAdd($store, 'couriers', Courier::forStore($store->id)->whereNotIn('status', ['inactive', 'declined'])->count());
        $profile = $this->profileFor($courier);

        $fiche = DB::transaction(function () use ($store, $courier, $profile, $data, $existing) {
            $fiche = $existing ?? new Courier(['store_id' => $store->id]);
            $fiche->forceFill([
                'store_id' => $store->id,
                'user_id' => $courier->id,
                'name' => $courier->name,
                'phone' => $courier->phone,
                'vehicle_type' => $profile->vehicle_type,
                'pay_rule_id' => $data['pay_rule_id'] ?? $fiche->pay_rule_id,
                'cash_limit' => $data['cash_limit'] ?? $fiche->cash_limit,
                'note' => $data['note'] ?? $fiche->note,
                'status' => 'requested',
                'is_available' => true,
                'requested_at' => now(),
                'answered_at' => null,
            ])->save();
            // Communes : celles choisies par la boutique, sinon celles du profil du livreur
            $places = $data['place_ids'] ?? $profile->places()->pluck('places.id')->all();
            $valid = Place::visibleTo($store->id)->whereIn('id', $places)->pluck('id');
            $fiche->places()->sync($valid->mapWithKeys(fn (int $id) => [$id => ['store_id' => $store->id]])->all());

            return $fiche;
        });

        $courier->notify(new CourierNetworkNotice($store->id, $store->name.' veut que vous livriez pour elle', 'Acceptez ou refusez dans votre application', '/livreur/compte'));

        return $fiche;
    }

    public function accept(Courier $fiche, User $user): Courier
    {
        $this->ensureOwnRequest($fiche, $user);
        $store = $fiche->store;
        $profile = $this->profileFor($user);
        if ($store->couriers_require_id && ! $profile->hasIdDocument()) {
            throw new BusinessRuleException($store->name.' demande votre pièce d\'identité : ajoutez-la dans votre profil, puis acceptez.', 'id_document');
        }

        DB::transaction(function () use ($fiche, $store, $user) {
            $store->members()->updateOrCreate(['user_id' => $user->id], ['status' => UserStatus::Active, 'joined_at' => now()]);
            if ($this->roles->roleOf($user, $store) === null) {
                $this->roles->assign($user, $store, Role::Courier);
            }
            $fiche->forceFill(['status' => 'active', 'answered_at' => now()])->save();
        });
        $this->team->notify($store, Permission::CouriersManage->value, new CourierNetworkNotice($store->id, $user->name.' a accepté de livrer pour vous',
            'Vous pouvez lui confier des courses', '/gestion/livraisons/livreurs'));

        return $fiche->fresh();
    }

    public function decline(Courier $fiche, User $user): Courier
    {
        $this->ensureOwnRequest($fiche, $user);
        $fiche->forceFill(['status' => 'declined', 'answered_at' => now(), 'is_available' => false])->save();
        $this->team->notify($fiche->store, Permission::CouriersManage->value, new CourierNetworkNotice($fiche->store_id, $user->name.' a refusé votre demande',
            'Cherchez un autre livreur dans l\'annuaire', '/gestion/livraisons/livreurs'));

        return $fiche;
    }

    /** La boutique retire sa demande (pas encore acceptée) */
    public function cancel(Courier $fiche): void
    {
        if (! in_array($fiche->status, ['requested', 'declined'], true)) {
            throw new BusinessRuleException('Ce livreur a déjà accepté : désactivez-le plutôt.');
        }
        DB::transaction(function () use ($fiche) {
            $fiche->places()->detach();
            $fiche->delete();
        });
    }

    /** Le livreur quitte une boutique : plus de course en cours ni d'espèces à lui verser */
    public function leave(Courier $fiche, User $user): void
    {
        if ($fiche->user_id !== $user->id || $fiche->status !== 'active') {
            throw new BusinessRuleException('Vous ne livrez pas pour cette boutique.');
        }
        if ($this->shared->held($fiche) > 0) {
            throw new BusinessRuleException('Versez d\'abord à '.$fiche->store->name.' les espèces que vous détenez.');
        }
        SharedCouriers::within($fiche->store, fn () => app(CourierRoster::class)->deactivate($fiche));
        $this->team->notify($fiche->store, Permission::CouriersManage->value, new CourierNetworkNotice($fiche->store_id, $user->name.' ne livre plus pour vous',
            'Il a quitté votre boutique depuis son application', '/gestion/livraisons/livreurs'));
    }

    /**
     * @param  array{vehicle_type?: string, place_ids?: list<int>, listed?: bool}  $data
     */
    public function updateProfile(CourierProfile $profile, array $data): CourierProfile
    {
        DB::transaction(function () use ($profile, $data) {
            $profile->fill(collect($data)->only(['vehicle_type', 'listed'])->all())->save();
            if (array_key_exists('place_ids', $data)) {
                $profile->places()->sync(Place::whereNull('store_id')->where('is_active', true)->whereIn('id', $data['place_ids'] ?? [])->pluck('id'));
            }
        });

        return $profile;
    }

    public function savePhoto(CourierProfile $profile, UploadedFile $file): void
    {
        $old = $profile->photo_path;
        $profile->forceFill(['photo_path' => $file->store("couriers/{$profile->id}", Files::privateDisk())])->save();
        if ($old) {
            Files::private()->delete($old);
        }
    }

    public function saveIdDocument(CourierProfile $profile, UploadedFile $file): void
    {
        $old = $profile->id_document_path;
        $profile->forceFill(['id_document_path' => $file->store("couriers/{$profile->id}/id", Files::privateDisk()), 'id_document_at' => now()])->save();
        if ($old) {
            Files::private()->delete($old);
        }
    }

    /**
     * Annuaire : livreurs inscrits visibles, qui livrent dans ce lieu (sa commune ou un de ses quartiers), pas encore
     * reliés à la boutique (ni demande en attente) ; ceux qui ont fourni leur pièce d'identité en premier.
     *
     * @return EloquentCollection<int, CourierProfile>
     */
    public function directory(Store $store, ?int $placeId = null): EloquentCollection
    {
        $taken = Courier::forStore($store->id)->whereNotIn('status', ['inactive'])->whereNotNull('user_id')->pluck('user_id');
        $place = $placeId ? Place::find($placeId) : null;
        $ids = $place ? collect([$place->id, $place->parent_id])->merge(Place::where('parent_id', $place->id)->pluck('id'))->filter()->all() : null;

        return CourierProfile::with(['user', 'places.parent'])->where('listed', true)
            ->whereHas('user', fn ($q) => $q->where('status', UserStatus::Active->value))
            ->whereNotIn('user_id', $taken)
            ->when($ids, fn ($q) => $q->whereHas('places', fn ($p) => $p->whereIn('places.id', $ids)))
            // Pièce d'identité fournie d'abord : elle augmente ses chances d'être choisi
            ->orderByRaw('id_document_path IS NULL')->latest('updated_at')->limit(60)->get();
    }

    private function ensureOwnRequest(Courier $fiche, User $user): void
    {
        if ($fiche->user_id !== $user->id || $fiche->status !== 'requested') {
            throw new BusinessRuleException('Cette demande n\'est plus en attente.');
        }
    }
}
