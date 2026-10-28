<?php

namespace App\Services\WhatsApp;

use App\Enums\StoreStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\OnboardingMessage;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;
use RuntimeException;

/**
 * Rattrapage des commerçants inscrits avant les messages d'accueil : un seul message WhatsApp par commerçant, lancé
 * depuis la console, selon sa boutique (sans produit, des produits mais pas de commande, déjà des ventes). Envoyé
 * doucement par la tâche onboarding:run (au plus PER_RUN toutes les 5 minutes, DAILY_LIMIT par jour, rien entre
 * 21 h et 7 h) ; avec la connexion officielle, par des modèles marketing que l'application crée chez Meta.
 */
class MerchantCatchUp
{
    public const STEP = 'catchup';

    public const PER_RUN = 100;

    /** Plafond par jour : Meta limite les conversations qu'un numéro récent peut ouvrir (250 par 24 h au départ) */
    public const DAILY_LIMIT = 200;

    /** Inscrits depuis moins longtemps : la série d'accueil s'en occupe */
    public const MIN_AGE_DAYS = 3;

    /**
     * Par groupe : texte envoyé (QR code ou dans les 24 h) et modèle Meta équivalent, variables {{1}} prénom,
     * {{2}} boutique, {{3}} lien.
     */
    public const GROUPS = [
        'empty' => [
            'label' => 'Boutique sans produit',
            'template' => 'jm_rattrapage_produits',
            'text' => "Bonjour {prenom} 👋\n\nVotre boutique *{boutique}* sur Jibiat Marchand vous attend : il ne manque que vos produits.\n\nPrenez une photo, mettez un prix, et c'est en ligne tout de suite. Vos clients peuvent commander directement sur WhatsApp. 2 minutes suffisent :\n👉 {lien}\n\nUne question ? Répondez à ce message, on vous aide.\nRépondez STOP pour ne plus recevoir nos messages.",
            'link' => '/gestion/catalogue/nouveau',
        ],
        'products' => [
            'label' => 'Des produits, pas encore de commande',
            'template' => 'jm_rattrapage_live',
            'text' => "Bonjour {prenom} 👋\n\nVos produits sont en ligne sur *{boutique}*. Prochaine étape pour vendre : la *vente en live*.\n\nPendant votre live TikTok ou Facebook, vos clients commandent eux-mêmes sur votre page, et vous voyez les commandes arriver en direct.\n👉 {lien}\n\nPensez aussi à partager le lien de votre boutique dans votre statut WhatsApp.\nRépondez STOP pour ne plus recevoir nos messages.",
            'link' => '/gestion/live',
        ],
        'selling' => [
            'label' => 'Boutique qui vend déjà',
            'template' => 'jm_rattrapage_nouveautes',
            'text' => "Bonjour {prenom} 👋\n\nBravo pour vos ventes sur *{boutique}* ! Du nouveau sur Jibiat Marchand pour vendre encore plus :\n\n🔴 *La vente en live* : vos clients commandent seuls pendant votre live TikTok ou Facebook.\n🚚 *L'étiquette de livraison* : imprimez-la en un clic pour chaque colis.\n🤖 *L'assistant* : il remplit vos commandes à partir des messages WhatsApp de vos clients.\n\nDécouvrez-les ici : {lien}\nRépondez STOP pour ne plus recevoir nos messages.",
            'link' => '/gestion',
        ],
    ];

    public function __construct(
        private readonly PlatformWhatsApp $whatsapp,
        private readonly WhatsAppCloud $cloud,
        private readonly MerchantOnboarding $onboarding,
    ) {}

    /**
     * @return array{state: string, started_at: ?string, sent: int, failed: int, remaining: int}
     */
    public function status(): array
    {
        $s = PlatformSetting::group('catchup');
        $rows = OnboardingMessage::where('step', self::STEP)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $state = $s['state'] ?? 'idle'; // idle, running, paused, done

        return [
            'state' => $state,
            'started_at' => $s['started_at'] ?? null,
            'sent' => (int) ($rows['sent'] ?? 0),
            'failed' => (int) ($rows['failed'] ?? 0),
            'remaining' => $state === 'idle' ? 0 : $this->eligible()->count(),
        ];
    }

    /**
     * Aperçu avant l'envoi : nombre de commerçants par groupe, texte rendu pour l'un d'eux, état des modèles Meta.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $eligible = $this->eligible();
        $groups = [];
        foreach (self::GROUPS as $key => $group) {
            $stores = $eligible->filter(fn (Store $s) => $this->group($s) === $key);
            $sample = $stores->first();
            $groups[$key] = [
                'label' => $group['label'],
                'count' => $stores->count(),
                'template' => $group['template'],
                'template_status' => $this->templateStatus($group['template']),
                'preview' => $this->text($sample ?? new Store(['name' => 'Awa Mode', 'slug' => 'awa-mode']), $key, $sample?->owner?->name ?? 'Awa'),
            ];
        }

        return ['groups' => $groups, 'total' => $eligible->count(), 'daily_limit' => self::DAILY_LIMIT, 'qr' => $this->whatsapp->isQr()] + $this->status();
    }

    public function start(): void
    {
        if (! $this->whatsapp->connected()) {
            throw new BusinessRuleException('Connectez d\'abord le WhatsApp du support.');
        }
        PlatformSetting::put('catchup.state', 'running');
        PlatformSetting::put('catchup.started_at', now()->toIso8601String());
    }

    public function pause(): void
    {
        PlatformSetting::put('catchup.state', 'paused');
    }

    /**
     * Commerçants à qui écrire : boutique ouverte depuis au moins 3 jours, numéro, pas de STOP, jamais reçu de message
     * d'accueil ni de rattrapage ; une seule boutique par commerçant (la plus ancienne).
     *
     * @return Collection<int, Store>
     */
    public function eligible(): Collection
    {
        $contacted = OnboardingMessage::whereIn('status', ['sent', 'failed'])->whereNotNull('user_id')->distinct()->pluck('user_id');

        return Store::query()->with('owner')
            ->where('status', '!=', StoreStatus::Closed->value)
            ->where('created_at', '<=', now()->subDays(self::MIN_AGE_DAYS))
            ->whereNotIn('owner_id', $contacted)
            ->whereNotIn('id', OnboardingMessage::where('step', self::STEP)->select('store_id'))
            ->whereHas('owner', fn ($q) => $q->whereNull('platform_messages_stopped_at')->whereNotNull('phone'))
            ->orderBy('created_at')->get()
            ->unique('owner_id')->values();
    }

    /**
     * Tâche toutes les 5 minutes, pendant un envoi lancé : un lot, sans dépasser le plafond du jour.
     */
    public function run(): int
    {
        if ((PlatformSetting::group('catchup')['state'] ?? null) !== 'running' || ! $this->whatsapp->connected()) {
            return 0;
        }
        $today = OnboardingMessage::where('step', self::STEP)->where('status', 'sent')->where('created_at', '>=', now()->startOfDay())->count();
        $room = min(self::PER_RUN, self::DAILY_LIMIT - $today);
        $eligible = $this->eligible();
        if ($eligible->isEmpty()) {
            PlatformSetting::put('catchup.state', 'done');

            return 0;
        }

        $sent = 0;
        foreach ($eligible as $store) {
            if ($sent >= $room) {
                break;
            }
            if ($this->onboarding->quiet($store)) {
                break;
            }
            $result = $this->deliver($store);
            if ($result === null) {
                // Modèle Meta pas encore approuvé : on attend, rien n'est noté
                continue;
            }
            $sent += $result ? 1 : 0;
        }

        return $sent;
    }

    /**
     * @return bool|null envoyé, échec (noté), ou null si le modèle de ce groupe n'est pas encore approuvé
     */
    private function deliver(Store $store): ?bool
    {
        $key = $this->group($store);
        $group = self::GROUPS[$key];
        $phone = PhoneNumber::normalize($store->owner?->phone);
        $text = $this->text($store, $key, (string) $store->owner?->name);

        try {
            if ($this->whatsapp->isQr() || ($phone !== null && $this->onboarding->windowOpen($phone))) {
                $this->whatsapp->sendAutomatic((string) $phone, $text);
            } elseif ($this->templateStatus($group['template']) === 'APPROVED') {
                $this->whatsapp->sendTemplateTo((string) $phone, $group['template'], [$this->firstName((string) $store->owner?->name), $store->name, $store->url($group['link'])], $text);
            } else {
                return null;
            }
        } catch (RuntimeException $e) {
            OnboardingMessage::firstOrCreate(['store_id' => $store->id, 'step' => self::STEP], ['user_id' => $store->owner_id, 'status' => 'failed', 'detail' => Str::limit($e->getMessage(), 250)]);

            return false;
        }
        OnboardingMessage::firstOrCreate(['store_id' => $store->id, 'step' => self::STEP], ['user_id' => $store->owner_id, 'status' => 'sent', 'detail' => $key]);

        return true;
    }

    public function group(Store $store): string
    {
        if (! $store->exists || Product::forStore($store->id)->count() === 0) {
            return 'empty';
        }

        return Order::forStore($store->id)->exists() ? 'selling' : 'products';
    }

    private function text(Store $store, string $key, string $name): string
    {
        $group = self::GROUPS[$key];

        return $this->onboarding->render($group['text'], [
            'prenom' => $this->firstName($name),
            'boutique' => $store->name,
            'lien' => $store->exists ? $store->url($group['link']) : 'https://awa-mode.'.config('platform.domain').$group['link'],
        ]);
    }

    private function firstName(string $name): string
    {
        return Str::before(trim($name), ' ') ?: 'cher commerçant';
    }

    // ── Modèles Meta (catégorie marketing), créés par l'application pour le numéro du support ──

    /**
     * @return array<string, string> état par modèle
     */
    public function installTemplates(): array
    {
        $account = $this->whatsapp->account() ?? throw new BusinessRuleException('Les modèles se créent seulement pour un numéro du support connecté avec Meta. Par QR code, ils ne sont pas nécessaires.');
        $result = [];
        foreach (self::GROUPS as $group) {
            $body = $this->templateBody($group['text']);
            try {
                $created = $this->cloud->graph('POST', $account->waba_id.'/message_templates', $account->access_token, [
                    'name' => $group['template'], 'language' => 'fr', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => $body, 'example' => ['body_text' => [['Awa', 'Awa Mode', 'https://awa-mode.jibiat-marchand.com'.$group['link']]]]]],
                ]);
                $status = strtoupper((string) ($created['status'] ?? 'PENDING'));
            } catch (RuntimeException $e) {
                if (! str_contains(mb_strtolower($e->getMessage()), 'already exists') && ! str_contains($e->getMessage(), 'existe déjà')) {
                    $status = 'ERROR';
                } else {
                    $status = $this->templateStatus($group['template']) ?? 'PENDING';
                }
            }
            PlatformSetting::put('catchup.template_'.$group['template'], $status);
            $result[$group['template']] = $status;
        }

        return $result;
    }

    public function templateStatus(string $name): ?string
    {
        return PlatformSetting::group('catchup')['template_'.$name] ?? null;
    }

    /**
     * Notification de Meta sur le numéro du support.
     */
    public function updateTemplateStatus(string $name, string $status): void
    {
        if (collect(self::GROUPS)->pluck('template')->contains($name)) {
            PlatformSetting::put('catchup.template_'.$name, strtoupper($status));
        }
    }

    /**
     * Texte du modèle : variables {{1}} {{2}} {{3}} à la place de {prenom} {boutique} {lien}.
     */
    public function templateBody(string $text): string
    {
        return strtr($text, ['{prenom}' => '{{1}}', '{boutique}' => '{{2}}', '{lien}' => '{{3}}']);
    }
}
