<?php

namespace App\Services\WhatsApp;

use App\Enums\StoreStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\OnboardingMessage;
use App\Models\PhoneVerification;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Billing\AccessKeys;
use App\Services\Billing\FreeAccess;
use App\Services\Shipping\CarrierDirectory;
use App\Support\Tenancy;
use App\Support\TrialOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;
use RuntimeException;

/**
 * Accueil des nouveaux commerçants par le WhatsApp du support (tâche toutes les 5 minutes) :
 *  - bienvenue, une minute après la création de la boutique (variante carte Membre Premium ou invitation d'une
 *    entreprise de livraison) ;
 *  - 3 h après, s'il n'a ajouté aucun produit : rappel du premier produit ;
 *  - 20 h après, s'il a des produits : la vente en live ;
 *  - 3 jours avant la fin de l'essai : choisir son offre.
 * Les trois premiers partent dans les 24 h qui suivent son message de vérification (réponse libre permise par Meta) ;
 * le dernier passe par un modèle validé par Meta avec la connexion officielle, tel quel par QR code. Rien entre 21 h
 * et 7 h ; « STOP » arrête tout. Textes modifiables dans la console.
 */
class MerchantOnboarding
{
    public const STEPS = ['welcome', 'no_product', 'first_products', 'trial_end'];

    public const TEXTS = [
        'welcome' => "Bienvenue sur Jibiat Marchand, {prenom} ! 🎉\n\nVotre boutique *{boutique}* est prête : {lien_boutique}\n{essai}\n\nPour bien démarrer, ajoutez votre premier produit avec une photo : 2 minutes suffisent.\n👉 {lien_produit}\n\nUne question ? Répondez simplement à ce message, on vous aide ici.",
        'welcome_premium' => "Bienvenue parmi les membres Premium, {prenom} ! 🏅\n\nVotre carte *n° {numero_carte}* est activée : offre *{offre}*. Votre boutique : {lien_boutique}\n\nPour bien démarrer, ajoutez votre premier produit : {lien_produit}",
        'welcome_partner' => "Bienvenue, {prenom} ! *{entreprise}* vous a invité sur Jibiat Marchand 🚚\n\n{essai} Vos commandes partent directement chez {entreprise} en un clic.\n👉 Ajoutez votre premier produit : {lien_produit}",
        'no_product' => "{prenom}, votre boutique attend son premier produit 🛍️\n\nPrenez une photo, donnez un prix : c'est en ligne tout de suite, et vos clients peuvent commander sur WhatsApp.\n👉 {lien_produit}\n\nPetite vidéo pour vous guider : {lien_video}",
        'first_products' => "Bravo {prenom}, {nb_produits} déjà dans votre boutique 👏\n\nProchaine étape : *vendre en live*. Pendant votre live TikTok ou Facebook, vos clients commandent eux-mêmes sur votre page, et vous voyez les commandes arriver en direct.\n👉 {lien_live}\n\nPartagez aussi le lien de votre boutique dans votre statut WhatsApp : {lien_boutique}",
        'trial_end' => "Bonjour {prenom}, votre essai {offre_essai} de *{boutique}* se termine le *{date_fin}*.\n\nPour garder toutes les fonctions, choisissez votre offre, à partir de {prix}, payable par Wave ou Orange Money.\n👉 {lien_abonnement}\n\nSinon, votre boutique passe à l'offre Gratuit, sans rien perdre.",
    ];

    /**
     * Modèle de fin d'essai créé chez Meta par l'application (numéro du support connecté avec Meta) : variables dans
     * l'ordre prénom, boutique, date de fin, prix, lien ; Meta refuse une variable en fin de texte.
     */
    public const TRIAL_TEMPLATE = [
        'name' => 'jm_fin_essai',
        'category' => 'UTILITY',
        'body' => 'Bonjour {{1}}, votre essai gratuit de {{2}} sur Jibiat Marchand se termine le {{3}}. Pour garder toutes les fonctions, choisissez votre offre à partir de {{4}} ici : {{5}} . Sinon, votre boutique passe à l\'offre Gratuit, sans rien perdre.',
        'example' => ['Awa', 'Awa Mode', '15 novembre', '1 500 F par semaine', 'https://awa-mode.jibiat-marchand.com/gestion/abonnement'],
    ];

    public const STOP_REPLY = 'C\'est noté : vous ne recevrez plus nos messages automatiques. Écrivez START pour les réactiver. Vous pouvez toujours nous écrire ici.';

    public const START_REPLY = 'C\'est noté : les messages automatiques de Jibiat Marchand sont réactivés.';

    /** Heures pendant lesquelles rien ne part (heure de la boutique) */
    private const QUIET_FROM = 21;

    private const QUIET_UNTIL = 7;

    public function __construct(private readonly PlatformWhatsApp $whatsapp, private readonly WhatsAppCloud $cloud) {}

    /**
     * @return array{enabled: bool, texts: array<string, string>, trial_template: ?string, trial_template_status: ?string}
     */
    public function settings(): array
    {
        $s = PlatformSetting::group('onboarding');
        $texts = [];
        foreach (self::TEXTS as $key => $default) {
            $texts[$key] = filled($s["text_{$key}"] ?? null) ? $s["text_{$key}"] : $default;
        }

        return [
            'enabled' => ($s['enabled'] ?? '1') === '1',
            'texts' => $texts,
            'trial_template' => filled($s['trial_template'] ?? null) ? $s['trial_template'] : null,
            // PENDING, APPROVED, REJECTED… (modèle créé par l'application), null pour un nom saisi à la main
            'trial_template_status' => $s['trial_template_status'] ?? null,
        ];
    }

    /**
     * Crée chez Meta le modèle de fin d'essai pour le numéro du support (connexion officielle). Déjà créé : gardé.
     * Renvoie l'état, ou lève une erreur lisible.
     */
    public function installTemplate(): string
    {
        $account = $this->whatsapp->account() ?? throw new BusinessRuleException('Le modèle se crée seulement pour un numéro du support connecté avec Meta (Facebook ou configuration manuelle). Par QR code, il n\'est pas nécessaire.');
        $t = self::TRIAL_TEMPLATE;
        try {
            $created = $this->cloud->graph('POST', $account->waba_id.'/message_templates', $account->access_token, [
                'name' => $t['name'], 'language' => 'fr', 'category' => $t['category'],
                'components' => [['type' => 'BODY', 'text' => $t['body'], 'example' => ['body_text' => [$t['example']]]]],
            ]);
            $status = strtoupper((string) ($created['status'] ?? 'PENDING'));
        } catch (RuntimeException $e) {
            if (! str_contains(mb_strtolower($e->getMessage()), 'already exists') && ! str_contains($e->getMessage(), 'existe déjà')) {
                throw new BusinessRuleException('Meta a refusé la création du modèle. '.$e->getMessage());
            }
            $status = $this->settings()['trial_template_status'] ?? 'PENDING';
        }
        PlatformSetting::put('onboarding.trial_template', $t['name']);
        PlatformSetting::put('onboarding.trial_template_status', $status);

        return $status;
    }

    /**
     * Notification de Meta sur le numéro du support : modèle approuvé, refusé…
     */
    public function updateTemplateStatus(string $name, string $status): void
    {
        if ($name !== '' && $name === $this->settings()['trial_template']) {
            PlatformSetting::put('onboarding.trial_template_status', strtoupper($status));
        }
    }

    /**
     * @param  array{enabled: bool, texts?: array<string, ?string>, trial_template?: ?string}  $data
     */
    public function update(array $data): void
    {
        PlatformSetting::put('onboarding.enabled', $data['enabled'] ? '1' : '0');
        foreach ($data['texts'] ?? [] as $key => $text) {
            if (array_key_exists($key, self::TEXTS)) {
                // Texte vide ou identique au texte proposé : on revient au texte proposé (mis à jour avec l'application)
                $text = trim((string) $text);
                PlatformSetting::put("onboarding.text_{$key}", $text === '' || $text === self::TEXTS[$key] ? null : $text);
            }
        }
        if (array_key_exists('trial_template', $data)) {
            $name = filled($data['trial_template']) ? trim($data['trial_template']) : null;
            if ($name !== $this->settings()['trial_template']) {
                // Nom saisi à la main : état inconnu ici
                PlatformSetting::put('onboarding.trial_template', $name);
                PlatformSetting::put('onboarding.trial_template_status', null);
            }
        }
    }

    /**
     * @return array<string, array{sent: int, skipped: int, failed: int}>
     */
    public function stats(): array
    {
        $rows = OnboardingMessage::where('created_at', '>=', now()->subDays(30))->selectRaw('step, status, count(*) as n')->groupBy('step', 'status')->get();

        return collect(self::STEPS)->mapWithKeys(fn (string $step) => [$step => [
            'sent' => (int) $rows->where('step', $step)->where('status', 'sent')->sum('n'),
            'skipped' => (int) $rows->where('step', $step)->where('status', 'skipped')->sum('n'),
            'failed' => (int) $rows->where('step', $step)->where('status', 'failed')->sum('n'),
        ]])->all();
    }

    /**
     * Tâche toutes les 5 minutes.
     *
     * @return array<string, int> messages envoyés par étape
     */
    public function run(): array
    {
        $sent = array_fill_keys(self::STEPS, 0);
        if (! $this->settings()['enabled'] || ! $this->whatsapp->connected()) {
            return $sent;
        }

        // Premières 24 h : bienvenue, premier produit, vente en live
        $recent = Store::query()->with('owner')->where('status', '!=', StoreStatus::Closed->value)
            ->where('created_at', '>=', now()->subHours(25))->where('created_at', '<=', now()->subMinute())->get();
        foreach ($recent as $store) {
            foreach (['welcome', 'no_product', 'first_products'] as $step) {
                if ($this->due($store, $step) && $this->deliver($store, $step)) {
                    $sent[$step]++;
                }
            }
        }

        // Fin de l'essai dans 2 à 3 jours
        $trials = Subscription::with(['store.owner', 'plan'])->where('status', SubscriptionStatus::Trialing->value)
            ->whereBetween('trial_ends_at', [now()->addDays(2), now()->addDays(3)])->get();
        foreach ($trials as $sub) {
            $store = $sub->store;
            if ($store !== null && $store->status !== StoreStatus::Closed && $this->due($store, 'trial_end') && $this->deliver($store, 'trial_end', $sub)) {
                $sent['trial_end']++;
            }
        }

        return $sent;
    }

    /**
     * L'étape est à faire maintenant pour cette boutique (sinon : déjà faite, pas encore l'heure, ou plus utile).
     */
    private function due(Store $store, string $step): bool
    {
        if (OnboardingMessage::where('store_id', $store->id)->where('step', $step)->exists()) {
            return false;
        }
        $age = $store->created_at;

        return match ($step) {
            'welcome' => true,
            'no_product', 'first_products' => OnboardingMessage::where('store_id', $store->id)->where('step', 'welcome')->where('status', 'sent')->exists()
                && $age->lte(now()->subHours($step === 'no_product' ? 3 : 20))
                && ($step === 'no_product') === (Product::forStore($store->id)->count() === 0),
            'trial_end' => app(FreeAccess::class)->planFor($store) === null,
            default => false,
        };
    }

    private function deliver(Store $store, string $step, ?Subscription $sub = null): bool
    {
        $owner = $store->owner;
        $phone = PhoneNumber::normalize($owner?->phone);
        if ($owner === null || $phone === null) {
            return $this->record($store, $step, 'skipped', 'Pas de numéro WhatsApp');
        }
        if ($owner->platform_messages_stopped_at !== null) {
            return $this->record($store, $step, 'skipped', 'Le commerçant a demandé STOP');
        }
        // Bienvenue une seule fois par commerçant (deuxième boutique : rien)
        if ($step === 'welcome' && OnboardingMessage::where('user_id', $owner->id)->where('step', 'welcome')->where('store_id', '!=', $store->id)->exists()) {
            return $this->record($store, $step, 'skipped', 'Commerçant déjà accueilli');
        }
        if ($this->quiet($store)) {
            return false;
        }

        $vars = $this->variables($store, $owner, $sub);
        $texts = $this->settings()['texts'];
        $key = $step === 'welcome' ? $this->welcomeVariant($store, $vars) : $step;
        $text = $this->render($texts[$key], $vars);

        try {
            if ($this->whatsapp->isQr() || $this->windowOpen($phone)) {
                $this->whatsapp->sendAutomatic($phone, $text);
            } elseif ($step === 'trial_end' && ($template = $this->settings()['trial_template']) !== null
                && ! in_array($this->settings()['trial_template_status'], ['PENDING', 'REJECTED', 'PAUSED', 'DISABLED'], true)) {
                $this->whatsapp->sendTemplateTo($phone, $template, [$vars['prenom'], $vars['boutique'], $vars['date_fin'], $vars['prix'], $vars['lien_abonnement']], $text);
            } else {
                return $this->record($store, $step, 'skipped', $step === 'trial_end'
                    ? 'Hors des 24 h et modèle Meta pas encore approuvé'
                    : 'Hors des 24 h après la vérification du numéro');
            }
        } catch (RuntimeException $e) {
            return $this->record($store, $step, 'failed', Str::limit($e->getMessage(), 250));
        }

        return $this->record($store, $step, 'sent');
    }

    /**
     * Étape notée une fois pour toutes ; vrai seulement pour un message envoyé.
     */
    private function record(Store $store, string $step, string $status, ?string $detail = null): bool
    {
        OnboardingMessage::firstOrCreate(['store_id' => $store->id, 'step' => $step], ['user_id' => $store->owner_id, 'status' => $status, 'detail' => $detail]);

        return $status === 'sent';
    }

    public function quiet(Store $store): bool
    {
        $hour = CarbonImmutable::now($store->timezone ?: 'Africa/Abidjan')->hour;

        return $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL;
    }

    /**
     * Le commerçant a écrit au support il y a moins de 24 h (message de vérification ou autre) : réponse libre permise.
     */
    public function windowOpen(string $phone): bool
    {
        $since = now()->subHours(23)->subMinutes(45);

        return PhoneVerification::where('phone', $phone)->whereNotNull('verified_at')->where('verified_at', '>=', $since)->exists()
            || SupportConversation::where('contact', $phone)->where('last_inbound_at', '>=', $since)->exists();
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function welcomeVariant(Store $store, array $vars): string
    {
        if (($key = app(AccessKeys::class)->present($store)) !== null && ($key['premium'] ?? false) && ($key['badge'] ?? null) !== null) {
            return 'welcome_premium';
        }

        return $store->referred_by !== null ? 'welcome_partner' : 'welcome';
    }

    /**
     * @return array<string, string>
     */
    private function variables(Store $store, User $owner, ?Subscription $sub): array
    {
        $sub ??= $store->currentSubscription()->with('plan')->first();
        $trialing = $sub?->status === SubscriptionStatus::Trialing && $sub->trial_ends_at !== null;
        $trialDays = $trialing ? max(1, (int) round($sub->created_at->diffInDays($sub->trial_ends_at))) : null;
        $key = app(AccessKeys::class)->present($store);
        $products = Product::forStore($store->id)->count();
        $weekly = Plan::where('weekly_price', '>', 0)->min('weekly_price');
        $monthly = Plan::where('monthly_price', '>', 0)->min('monthly_price');
        $money = fn (int $amount) => number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}F";

        return [
            'prenom' => Str::before(trim((string) $owner->name), ' ') ?: 'cher commerçant',
            'boutique' => $store->name,
            'lien_boutique' => rtrim($store->url('/'), '/'),
            'lien_produit' => $store->url('/gestion/catalogue/nouveau'),
            'lien_live' => $store->url('/gestion/live'),
            'lien_abonnement' => $store->url('/gestion/abonnement'),
            'lien_video' => Tenancy::platformUrl('/videos/etapes/etape-06.mp4'),
            'nb_produits' => $products.' produit'.($products > 1 ? 's' : ''),
            'essai' => $trialing ? 'Vous avez *'.TrialOffer::duration($trialDays).' de l\'offre '.$sub->plan->name.' offert*, sans carte bancaire.' : '',
            'offre_essai' => $trialing ? $sub->plan->name : '',
            'date_fin' => $trialing ? $sub->trial_ends_at->locale('fr')->isoFormat('D MMMM') : '',
            'prix' => $weekly ? $money((int) $weekly).' par semaine' : ($monthly ? $money((int) $monthly).' par mois' : 'quelques francs'),
            'numero_carte' => $key !== null && ($key['badge'] ?? null) !== null ? Str::after($key['badge'], 'n° ') : '',
            'offre' => $key !== null ? $key['plan'].($key['founder'] ? ' à vie' : ($key['until'] ? ' jusqu\'au '.CarbonImmutable::parse($key['until'])->locale('fr')->isoFormat('D MMMM') : '')) : '',
            'entreprise' => $this->partnerName($store->referred_by),
        ];
    }

    private function partnerName(?string $slug): string
    {
        if ($slug === null) {
            return '';
        }

        return rescue(fn () => app(CarrierDirectory::class)->find($slug)['name'] ?? null, null, false) ?? 'Votre entreprise de livraison';
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function render(string $text, array $vars): string
    {
        $out = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $text) ?? $text;

        // Ligne laissée vide par une variable sans valeur : pas de trou dans le message
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/^[ \t]+$/m', '', $out)) ?? $out);
    }

    /**
     * Message reçu par le support : « STOP » arrête les messages automatiques, « START » les reprend. Renvoie la
     * réponse à envoyer, ou null si ce n'est pas l'un des deux.
     */
    public function handleKeyword(string $phone, string $text): ?string
    {
        $word = mb_strtoupper(trim($text, " \t\n\r.!"));
        if (! in_array($word, ['STOP', 'ARRET', 'ARRÊT', 'START'], true)) {
            return null;
        }
        $users = User::where('phone', PhoneNumber::normalize($phone) ?? $phone)->get();
        if ($users->isEmpty()) {
            return null;
        }
        $stop = $word !== 'START';
        $users->each(fn (User $u) => $u->forceFill(['platform_messages_stopped_at' => $stop ? now() : null])->save());

        return $stop ? self::STOP_REPLY : self::START_REPLY;
    }
}
