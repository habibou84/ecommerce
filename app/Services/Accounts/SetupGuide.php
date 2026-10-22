<?php

namespace App\Services\Accounts;

use App\Enums\Permission;
use App\Models\CarrierConnection;
use App\Models\Courier;
use App\Models\DeliveryZone;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\PushSubscription;
use App\Models\ScheduledReport;
use App\Models\SocialAccount;
use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLink;
use App\Support\StartSteps;

/**
 * Guide de démarrage pas à pas : chaque étape dit pourquoi elle compte, quoi faire et où. Une étape est « faite »
 * d'après les données de la boutique (rien à cocher à la main) ; on peut en passer une et la reprendre plus tard.
 * Chacun ne voit que les étapes que son rôle permet de faire. Les étapes facultatives ne comptent pas dans
 * l'avancement.
 */
class SetupGuide
{
    public const PHASES = [
        'store' => 'Votre espace marchand',
        'catalog' => 'Vos produits',
        'delivery' => 'Vos livraisons',
        'selling' => 'Vendre et encaisser',
        'more' => 'Pour aller plus loin',
    ];

    public function __construct(private readonly StoreRoles $roles) {}

    /**
     * @return array{steps: list<array<string, mixed>>, phases: array<string, string>, done: int, total: int, next: ?string}
     */
    public function forUser(Store $store, User $user): array
    {
        $skipped = $store->setup_guide['skipped'] ?? [];
        $steps = collect($this->definitions())
            ->filter(fn (array $s) => $this->allowed($store, $user, $s['permission']))
            ->map(function (array $s, string $key) use ($store, $user, $skipped) {
                $done = (bool) ($s['done'])($store, $user);

                return [
                    'key' => $key,
                    'phase' => $s['phase'],
                    'title' => $s['title'],
                    'why' => $s['why'],
                    'todo' => $s['todo'],
                    'to' => $s['to'],
                    'action' => $s['action'],
                    'optional' => $s['optional'] ?? false,
                    'done' => $done,
                    'skipped' => ! $done && in_array($key, $skipped, true),
                    // Courte vidéo « Les étapes essentielles pour commencer »
                    'video' => StartSteps::forGuide($key),
                ];
            })->values();

        $required = $steps->where('optional', false);
        $next = $steps->first(fn ($s) => ! $s['done'] && ! $s['skipped'] && ! $s['optional'])
            ?? $steps->first(fn ($s) => ! $s['done'] && ! $s['skipped']);

        return [
            'steps' => $steps->all(),
            'phases' => self::PHASES,
            'done' => $required->where('done', true)->count(),
            'total' => $required->count(),
            'next' => $next['key'] ?? null,
        ];
    }

    /**
     * Passer une étape (ou la reprendre).
     */
    public function skip(Store $store, User $user, string $key, bool $skipped): void
    {
        $step = $this->definitions()[$key] ?? abort(404);
        abort_unless($this->allowed($store, $user, $step['permission']), 403);

        $list = collect($store->setup_guide['skipped'] ?? [])->reject(fn ($k) => $k === $key);
        if ($skipped) {
            $list->push($key);
        }
        $store->forceFill(['setup_guide' => ['skipped' => $list->values()->all()] + ($store->setup_guide ?? [])])->save();
    }

    private function allowed(Store $store, User $user, ?Permission $permission): bool
    {
        return $permission === null || $this->roles->can($user, $store, $permission);
    }

    /**
     * @return array<string, array{phase: string, title: string, why: string, todo: list<string>, to: string, action: string, permission: ?Permission, optional?: bool, done: \Closure}>
     */
    private function definitions(): array
    {
        return [
            'mobile_app' => [
                'phase' => 'store', 'permission' => null,
                'title' => 'L\'application sur votre téléphone',
                'why' => 'Votre boutique dans la poche, et les nouvelles commandes et alertes en notification, même application fermée.',
                'todo' => [
                    'Android : dans Chrome, touchez ⋮ puis « Installer l\'application »',
                    'iPhone : dans Safari, touchez Partager puis « Sur l\'écran d\'accueil »',
                    'Ouvrez-la depuis l\'icône, puis Mon compte › « Activer les notifications »',
                    'Sur ordinateur : rien à installer, activez aussi les notifications dans Mon compte',
                ],
                'to' => '/gestion/compte', 'action' => 'Activer les notifications',
                'done' => fn (Store $s, User $u) => DeviceToken::where('user_id', $u->id)->exists() || PushSubscription::where('user_id', $u->id)->exists(),
            ],
            'store_info' => [
                'phase' => 'store', 'permission' => Permission::StoreManage,
                'title' => 'Les informations de votre espace marchand',
                'why' => 'Vos clients voient votre nom, votre slogan et votre numéro WhatsApp sur la boutique en ligne, les messages et les reçus.',
                'todo' => ['Ajoutez un slogan court', 'Indiquez le numéro WhatsApp où vos clients vous écrivent', 'Ajoutez votre adresse si vous recevez des clients'],
                'to' => '/gestion/parametres', 'action' => 'Compléter les informations',
                'done' => fn (Store $s) => filled($s->tagline) && filled($s->whatsapp_phone),
            ],
            'delivery_zones' => [
                'phase' => 'store', 'permission' => Permission::StoreManage,
                'title' => 'Vos frais de livraison par quartier',
                'why' => 'Le bon prix de livraison s\'affiche tout seul à chaque commande, selon le quartier du client.',
                'todo' => ['Une zone « Abidjan » à 1 500 F existe déjà', 'Créez une zone par groupe de quartiers au même prix', 'Indiquez le délai de livraison de chaque zone'],
                'to' => '/gestion/livraisons/zones', 'action' => 'Régler les zones',
                'done' => fn (Store $s) => DeliveryZone::forStore($s->id)->count() > 1
                    || DeliveryZone::forStore($s->id)->whereColumn('updated_at', '>', 'created_at')->exists(),
            ],
            'delivery_days' => [
                'phase' => 'store', 'permission' => Permission::StoreManage,
                'title' => 'Vos jours et heures de livraison',
                'why' => 'Chaque commande reçoit d\'office la bonne date de livraison : le jour même avant votre heure limite, sinon le jour suivant.',
                'todo' => ['Choisissez l\'heure limite pour livrer le jour même', 'Indiquez les jours sans livraison (le dimanche par exemple)'],
                'to' => '/gestion/livraisons/zones', 'action' => 'Régler les jours',
                'done' => fn (Store $s) => $s->delivery_calendar !== null,
            ],
            'products' => [
                'phase' => 'catalog', 'permission' => Permission::ProductsManage,
                'title' => 'Vos produits et leur stock',
                'why' => 'Une commande se saisit en quelques secondes, le stock se met à jour tout seul et vous savez quoi racheter.',
                'todo' => ['Ajoutez vos produits avec leurs prix', 'Indiquez les tailles et couleurs (variantes)', 'Saisissez le stock disponible', 'Beaucoup de produits ? Importez un fichier Excel'],
                'to' => '/gestion/catalogue/nouveau', 'action' => 'Ajouter un produit',
                'done' => fn (Store $s) => Product::forStore($s->id)->where('status', 'active')->exists(),
            ],
            'photos' => [
                'phase' => 'catalog', 'permission' => Permission::ProductsManage,
                'title' => 'Des photos pour vos produits',
                'why' => 'Sur la boutique en ligne et les réseaux, un produit avec photo se vend beaucoup mieux.',
                'todo' => ['Prenez vos produits en photo sur un fond clair', 'Ajoutez au moins une photo par produit', 'Astuce : l\'IA peut rédiger la fiche à partir de la photo'],
                'to' => '/gestion/catalogue', 'action' => 'Voir le catalogue',
                'done' => function (Store $s) {
                    $total = Product::forStore($s->id)->where('status', 'active')->count();

                    return $total > 0 && Product::forStore($s->id)->where('status', 'active')->whereHas('images')->count() >= 0.8 * $total;
                },
            ],
            'deliverers' => [
                'phase' => 'delivery', 'permission' => Permission::CouriersManage,
                'title' => 'Qui livre vos colis',
                'why' => 'Vous confiez une commande en un clic : à votre livreur, qui la reçoit sur son téléphone, ou à une entreprise de livraison.',
                'todo' => ['Ajoutez vos livreurs (ils reçoivent un lien pour l\'application)', 'Ou reliez votre entreprise de livraison (Jibiat ou autre)'],
                'to' => '/gestion/livraisons/livreurs', 'action' => 'Ajouter un livreur',
                'done' => fn (Store $s) => Courier::forStore($s->id)->where('status', 'active')->exists()
                    || CarrierConnection::forStore($s->id)->where('status', 'active')->exists(),
            ],
            'alerts' => [
                'phase' => 'delivery', 'permission' => Permission::StoreManage, 'optional' => true,
                'title' => 'Vos alertes',
                'why' => 'Vous êtes prévenu quand une commande attend : pas confirmée, sans livreur, pas ramassée, problème de livraison.',
                'todo' => ['Vérifiez les heures des alertes', 'Ajustez les délais ou coupez ce qui ne vous sert pas'],
                'to' => '/gestion/alertes', 'action' => 'Voir les alertes',
                'done' => fn (Store $s) => $s->alert_settings !== null,
            ],
            'whatsapp' => [
                'phase' => 'selling', 'permission' => Permission::StoreManage,
                'title' => 'Votre WhatsApp',
                'why' => 'Les clients reçoivent la confirmation, le suivi de livraison et le reçu sur WhatsApp, et leurs messages arrivent dans votre boîte Messages.',
                'todo' => ['Reliez votre WhatsApp par QR code : les messages des clients arrivent dans Messages', 'Pour les messages automatiques, connectez aussi votre numéro WhatsApp Business avec Facebook'],
                'to' => '/gestion/whatsapp', 'action' => 'Connecter WhatsApp',
                'done' => fn (Store $s) => WhatsappAccount::forStore($s->id)->where('status', 'active')->exists() || WhatsappLink::connectedFor($s->id) !== null,
            ],
            'online_store' => [
                'phase' => 'selling', 'permission' => Permission::StoreManage,
                'title' => 'Votre boutique en ligne',
                'why' => 'Vos clients commandent seuls, jour et nuit, depuis le lien que vous partagez sur WhatsApp, Facebook ou TikTok.',
                'todo' => ['Choisissez un thème et vos couleurs', 'Ajoutez votre logo', 'Ouvrez la boutique'],
                'to' => '/gestion/boutique-en-ligne', 'action' => 'Préparer la boutique',
                'done' => fn (Store $s) => (bool) $s->storefront_enabled,
            ],
            'online_payment' => [
                'phase' => 'selling', 'permission' => Permission::PaymentsManage, 'optional' => true,
                'title' => 'Le paiement en ligne',
                'why' => 'Wave, Orange Money, MTN ou carte : moins de commandes refusées à la porte, acompte possible sur les commandes à risque.',
                'todo' => ['Reliez votre compte de paiement (Wave, CinetPay ou PayDunya)', 'Choisissez : paiement au choix du client, exigé, ou acompte'],
                'to' => '/gestion/paiements', 'action' => 'Activer le paiement en ligne',
                'done' => fn (Store $s) => PaymentAccount::forStore($s->id)->where('status', 'active')->exists(),
            ],
            'first_order' => [
                'phase' => 'selling', 'permission' => Permission::OrdersManage,
                'title' => 'Votre première commande',
                'why' => 'Vous voyez tout le parcours : confirmation, livraison, encaissement et statistiques.',
                'todo' => ['Collez le message WhatsApp du client : tout se remplit', 'Choisissez la date et qui livre', 'Suivez-la dans la liste des commandes'],
                'to' => '/gestion/commandes/nouvelle', 'action' => 'Saisir une commande',
                'done' => fn (Store $s) => Order::forStore($s->id)->exists(),
            ],
            'team' => [
                'phase' => 'more', 'permission' => Permission::TeamManage, 'optional' => true,
                'title' => 'Votre équipe',
                'why' => 'Chacun a son accès et ne voit que ce qu\'il doit voir : gérant, agent de confirmation, préparateur, caissier.',
                'todo' => ['Invitez vos collaborateurs par leur numéro', 'Choisissez le rôle de chacun'],
                'to' => '/gestion/equipe', 'action' => 'Inviter l\'équipe',
                'done' => fn (Store $s) => StoreUser::where('store_id', $s->id)->count() > 1
                    || StoreInvitation::where('store_id', $s->id)->whereNull('revoked_at')->exists(),
            ],
            'daily_report' => [
                'phase' => 'more', 'permission' => Permission::OrdersView, 'optional' => true,
                'title' => 'Le rapport du soir',
                'why' => 'Chaque soir, les chiffres du jour et trois conseils, par WhatsApp ou par e-mail, sans ouvrir l\'application.',
                'todo' => ['Choisissez WhatsApp ou e-mail', 'Choisissez l\'heure d\'envoi'],
                'to' => '/gestion/statistiques', 'action' => 'Programmer le rapport',
                'done' => fn (Store $s, User $u) => ScheduledReport::withoutGlobalScopes()->where('store_id', $s->id)->where('user_id', $u->id)->where('is_active', true)->exists(),
            ],
            'marketing' => [
                'phase' => 'more', 'permission' => Permission::StoreManage, 'optional' => true,
                'title' => 'Réseaux sociaux et publicité',
                'why' => 'Publiez vos produits sur Facebook, Instagram et TikTok en un clic, et mesurez ce que vos publicités rapportent.',
                'todo' => ['Connectez vos pages Facebook, Instagram ou TikTok', 'Ajoutez votre pixel Meta ou TikTok si vous faites de la publicité'],
                'to' => '/gestion/marketing/reseaux', 'action' => 'Connecter les réseaux',
                'done' => fn (Store $s) => SocialAccount::forStore($s->id)->where('status', 'active')->exists()
                    || collect(['meta_pixel_id', 'tiktok_pixel_id', 'ga4_id'])->contains(fn ($k) => $s->marketingId($k)),
            ],
        ];
    }
}
