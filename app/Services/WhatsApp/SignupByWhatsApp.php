<?php

namespace App\Services\WhatsApp;

use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Jobs\DraftSignupProduct;
use App\Models\OnboardingMessage;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\SupportConversation;
use App\Models\User;
use App\Models\WhatsappSignup;
use App\Rules\StoreSlug;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\StoreSignup;
use App\Services\Billing\AccessKeys;
use App\Services\Catalog\ProductDrafter;
use App\Services\Shipping\CarrierDirectory;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;
use RuntimeException;
use Throwable;

/**
 * Inscription par WhatsApp, sur le numéro du support : la personne écrit « Inscription », confirme son numéro
 * (prouvé par le message lui-même), donne le nom de sa boutique, son prénom, dit si elle vend en live, valide le
 * récapitulatif : la boutique est créée et un lien de connexion à usage unique (15 minutes) lui est envoyé, jamais de
 * mot de passe. Puis la photo d'un article devient sa première fiche produit (IA).
 *
 * Aussi : « live » (premier live expliqué), « aide » (l'équipe prend la main). Un humain qui répond (console ou
 * téléphone) met le robot en pause 6 heures pour cette personne ; « Inscription » le relance. Une relance si la
 * personne s'arrête en route (3 h après, une fois).
 */
class SignupByWhatsApp
{
    public const PAUSE_HOURS = 6;

    public const REMIND_AFTER_HOURS = 3;

    public const MAX_STORES_PER_DAY = 3;

    public const LINK_TTL = 900;

    /** Étapes en cours (la personne doit répondre) */
    public const ACTIVE = ['confirm', 'existing', 'name', 'first_name', 'live', 'recap', 'photo'];

    /** Étapes de l'entonnoir, dans l'ordre */
    public const FUNNEL = ['started', 'confirmed', 'name', 'first_name', 'live', 'created', 'photo'];

    public const KEY_PHRASE = 'Toi, tu fais ton live sur TikTok. Jibiat Marchand enregistre les commandes.';

    private const FORBIDDEN = ['jibiat', 'whatsapp', 'facebook', 'tiktok', 'instagram', 'meta'];

    /** Messages dont le texte sert de réponse (texte tapé, bouton touché) */
    private const TYPED = ['text', 'button', 'interactive'];

    /** Réponses qui ne sont pas un nom (salutations, oui/non, étiquettes des messages sans texte) */
    private const NOT_A_NAME = ['ok', 'okay', 'oui', 'non', 'merci', 'bonjour', 'bonsoir', 'salut', 'slt', 'cc', 'coucou', 'hello', 'hi', 'yo',
        'svp', 's il vous plait', 'd accord', 'test', 'autocollant', 'message vocal', 'photo', 'video', 'document', 'position', 'audio',
        'vocal', 'sticker', 'emoji', 'rien', 'quoi', 'hein', 'ah', 'eh', 'oh', 'lol', 'mdr'];

    /** Réponses incomprises de suite avant de passer la main à l'équipe */
    public const MAX_MISSES = 3;

    /** @var array<string, string> numéro du compte => numéro WhatsApp qui écrit (peut être l'ancien à 8 chiffres) */
    private array $contacts = [];

    public function __construct(private readonly PlatformWhatsApp $whatsapp) {}

    public static function enabled(): bool
    {
        return (PlatformSetting::group('whatsapp_signup')['enabled'] ?? '1') === '1';
    }

    public static function setEnabled(bool $on): void
    {
        PlatformSetting::put('whatsapp_signup.enabled', $on ? '1' : '0');
    }

    /**
     * Message reçu sur le numéro du support (déjà rangé dans la console). Répond s'il s'agit de l'inscription, du mot
     * « live » ou de « aide » ; sinon rien (l'équipe répond). $mediaId : photo reçue ('' par QR code, illisible ici).
     * $kind : type du message (text, image, audio, sticker…) ; seul un texte tapé (ou un bouton) sert de réponse,
     * jamais l'étiquette d'un vocal ou d'un autocollant (« 🎤 Message vocal », « Autocollant »).
     */
    public function handle(string $from, string $text, ?string $payload = null, ?string $mediaId = null, string $kind = 'text'): bool
    {
        if (! in_array($kind, self::TYPED, true)) {
            $text = '';
        }
        // Le compte WhatsApp peut encore porter l'ancien numéro ivoirien à 8 chiffres (+225 53 14 16 66) : le compte
        // Jibiat Marchand est celui du numéro à 10 chiffres (+225 01 53 14 16 66) ; les réponses partent au numéro qui écrit
        $phone = PhoneNumber::modernize($from);
        $contact = PhoneNumber::normalize($from);
        if ($phone === null || $contact === null || ! self::enabled() || ! $this->whatsapp->connected()) {
            return false;
        }
        $this->contacts[$phone] = $contact;
        $words = self::words($text);
        // Inscription réservée aux mobiles de Côte d'Ivoire : un numéro étranger reçoit une seule réponse, puis le robot
        // le laisse à l'équipe (ses messages restent dans la console)
        if (! PhoneNumber::isLocalMobile($phone)) {
            if ((self::trigger($words) !== null || $payload === 'su:start') && Cache::add('signup-foreign:'.$phone, true, now()->addDays(30))) {
                $this->say($phone, 'Désolé, Jibiat Marchand est pour l\'instant réservé aux numéros de Côte d\'Ivoire 🇨🇮.');

                return true;
            }

            return false;
        }
        $signup = WhatsappSignup::firstOrNew(['phone' => $phone]);
        if (($signup->data['wa'] ?? null) !== $contact) {
            $signup->forceFill(['data' => array_merge($signup->data ?? [], ['wa' => $contact])]);
            if ($signup->exists) {
                $signup->save();
            }
        }
        $trigger = self::trigger($words);

        // Pause : un humain s'occupe de la personne ; « Inscription » relance le robot
        if ($signup->exists && $signup->paused() && $trigger === null) {
            $this->touch($signup);

            return false;
        }
        // Choix par numéro (QR code : « Tape 1 ») ou bouton touché ; une correspondance ne sert qu'une fois
        if ($payload === null && ($payload = $signup->data['choices'][trim($text)] ?? null) !== null) {
            $signup->forceFill(['data' => array_merge($signup->data, ['choices' => []])])->save();
        }
        if ($payload === 'su:start') {
            $trigger = ['code' => null];
        }

        if ($payload === 'su:help' || in_array($words, ['aide', 'help', 'au secours', 'j ai besoin d aide'], true)) {
            return $this->help($signup);
        }
        if ($trigger !== null) {
            return $this->start($signup, $trigger);
        }

        $active = $signup->exists && in_array($signup->step, self::ACTIVE, true);
        if ($active && in_array($words, ['annuler', 'stop inscription', 'laisse tomber'], true)) {
            $signup->fill(['step' => 'idle', 'data' => []])->save();
            $this->say($phone, "D'accord, j'arrête l'inscription. Écris *Inscription* quand tu veux recommencer 🙂");

            return true;
        }
        if ($active && $words === 'modifier' && ! in_array($signup->step, ['photo', 'existing'], true)) {
            return $this->ask($signup, 'name');
        }
        if ($active) {
            $this->touch($signup);
            // Vocal, autocollant, vidéo… (ou photo hors de l'étape photo) : pas une réponse, la question est reposée
            if ($payload === null && ! in_array($kind, self::TYPED, true) && ! ($mediaId !== null && $signup->step === 'photo')) {
                return $this->notUnderstood($signup, $kind);
            }

            return $this->answer($signup, $text, $payload, $mediaId);
        }
        if ($payload === 'lv:wa' || $payload === 'lv:link') {
            return $this->liveLink($phone, $payload);
        }
        if ($words === 'live' || $words === 'mon premier live' || $words === 'premier live') {
            return $this->live($phone);
        }

        return false;
    }

    /**
     * Un humain a répondu à cette personne (console ou téléphone) : le robot se tait pendant 6 heures.
     */
    public function pause(string $contact): void
    {
        $phone = PhoneNumber::modernize($contact);
        if ($phone !== null) {
            WhatsappSignup::updateOrCreate(['phone' => $phone], ['paused_until' => now()->addHours(self::PAUSE_HOURS)]);
        }
    }

    /**
     * Relance unique des inscriptions arrêtées en route depuis 3 h (dans les 24 h permises par Meta, hors nuit).
     */
    public function remind(): int
    {
        $hour = CarbonImmutable::now('Africa/Abidjan')->hour;
        if (! self::enabled() || ! $this->whatsapp->connected() || $hour >= 21 || $hour < 7) {
            return 0;
        }
        $sent = 0;
        $due = WhatsappSignup::whereIn('step', ['confirm', 'name', 'first_name', 'live', 'recap'])->whereNull('reminded_at')
            ->where('last_inbound_at', '<=', now()->subHours(self::REMIND_AFTER_HOURS))->where('last_inbound_at', '>=', now()->subHours(23))
            ->where(fn ($q) => $q->whereNull('paused_until')->orWhere('paused_until', '<', now()))->limit(50)->get();
        foreach ($due as $signup) {
            $signup->forceFill(['reminded_at' => now()])->save();
            try {
                $this->say($signup->phone, 'Tu y es presque 😊 Ta boutique est à une ou deux réponses d\'être prête.');
                $this->ask($signup, $signup->step);
                $sent++;
            } catch (Throwable) {
                // Numéro injoignable : la relance ne repart pas
            }
        }

        return $sent;
    }

    /**
     * Entonnoir des 30 derniers jours : combien de personnes ont atteint chaque étape.
     *
     * @return array<string, int>
     */
    public function funnel(): array
    {
        $rows = WhatsappSignup::where('created_at', '>=', now()->subDays(30))->whereNotNull('reached')->pluck('reached');

        return collect(self::FUNNEL)->mapWithKeys(fn (string $step) => [$step => $rows->filter(fn ($r) => isset($r[$step]))->count()])->all();
    }

    // ── Conversation ──

    /** @param  array{code: ?string}  $trigger */
    private function start(WhatsappSignup $signup, array $trigger): bool
    {
        $phone = $signup->phone;
        $data = ['code' => $trigger['code'], 'wa' => $signup->data['wa'] ?? $phone];
        $user = User::where('phone', $phone)->first();
        $signup->fill(['step' => 'confirm', 'data' => $data, 'paused_until' => null, 'reminded_at' => null, 'completed_at' => null])->forceFill(['last_inbound_at' => now()]);
        $this->reach($signup, 'started');

        if ($user !== null && ($store = $user->stores()->orderBy('stores.id')->first()) !== null) {
            $signup->forceFill(['step' => 'existing', 'store_id' => $store->id])->save();
            // Numéro déjà inscrit : seulement la connexion (une autre boutique se crée depuis la gestion)
            $this->choices($signup, 'Re-bonjour '.self::firstName($user->name)." 👋 Ce numéro a déjà la boutique *{$store->name}*.", ['su:login' => 'Me connecter']);

            return true;
        }
        $signup->save();
        $this->choices($signup, "Bienvenue sur Jibiat Marchand 👋\n".self::KEY_PHRASE."\n\nTu veux créer ta boutique avec ce numéro : *".PhoneNumber::display($phone).'* ?', [
            'su:yes' => '✅ Oui', 'su:other' => 'Autre numéro',
        ]);

        return true;
    }

    private function answer(WhatsappSignup $signup, string $text, ?string $payload, ?string $mediaId): bool
    {
        $value = trim($text);
        $yes = $payload === 'su:yes' || in_array(self::words($value), ['oui', 'ok', 'd accord', 'yes', 'ouais', 'oui oui'], true);

        switch ($signup->step) {
            case 'existing':
                if ($payload === 'su:login' || in_array(self::words($value), ['oui', 'ok', 'connexion', 'me connecter', 'connecter'], true)) {
                    return $this->sendLogin($signup);
                }

                return $this->missed($signup) ?: $this->ask($signup, 'existing');

            case 'confirm':
                if ($payload === 'su:other' || self::words($value) === 'autre numero') {
                    $signup->fill(['step' => 'idle'])->save();
                    $this->say($signup->phone, "D'accord. Le plus simple : écris *Inscription* depuis le WhatsApp de ce numéro-là 🙂\nOu inscris-toi sur le site : ".Tenancy::platformUrl('/creer-ma-boutique'));

                    return true;
                }
                if (! $yes) {
                    return $this->missed($signup) ?: $this->ask($signup, 'confirm');
                }
                $this->reach($signup, 'confirmed');

                return $this->ask($signup, 'name');

            case 'name':
                $error = self::nameError($value);
                if ($error !== null) {
                    return $this->missed($signup) ?: $this->sayTrue($signup, $error);
                }
                $this->put($signup, ['store_name' => Str::limit(preg_replace('/\s+/', ' ', $value), 60, '')]);
                $this->reach($signup, 'name');
                $owner = User::where('phone', $signup->phone)->first();

                return $this->ask($signup, $owner !== null ? 'live' : 'first_name');

            case 'first_name':
                $name = Str::limit(preg_replace('/\s+/', ' ', $value), 40, '');
                if (self::notAName($name) || preg_match('/https?:|www\.|\d{4,}/i', $name)) {
                    return $this->missed($signup) ?: $this->sayTrue($signup, 'Écris juste ton prénom, par exemple *Awa* 🙂');
                }
                $this->put($signup, ['first_name' => mb_convert_case($name, MB_CASE_TITLE)]);
                $this->reach($signup, 'first_name');

                return $this->ask($signup, 'live');

            case 'live':
                $live = match (true) {
                    $payload === 'su:live-yes', in_array(self::words($value), ['oui', 'oui en live', 'en live', 'live'], true) => true,
                    $payload === 'su:live-no', in_array(self::words($value), ['non', 'pas encore', 'pas du tout'], true) => false,
                    default => null,
                };
                if ($live === null) {
                    return $this->missed($signup) ?: $this->ask($signup, 'live');
                }
                $this->put($signup, ['live' => $live]);
                $this->reach($signup, 'live');

                return $this->ask($signup, 'recap');

            case 'recap':
                if ($payload === 'su:edit' || self::words($value) === 'modifier') {
                    return $this->ask($signup, 'name');
                }
                if ($payload !== 'su:create' && ! $yes && self::words($value) !== 'creer') {
                    return $this->missed($signup) ?: $this->ask($signup, 'recap');
                }

                return $this->create($signup);

            case 'photo':
                if ($mediaId !== null) {
                    return $this->photo($signup, $mediaId);
                }
                if (in_array(self::words($value), ['plus tard', 'non', 'pas maintenant', 'apres'], true)) {
                    $signup->fill(['step' => 'done'])->save();
                    $this->say($signup->phone, 'Pas de souci 👍 Ajoute tes articles quand tu veux depuis ta boutique. Écris *live* pour savoir comment vendre en live.');

                    return true;
                }

                return $this->missed($signup) ?: $this->photoReminder($signup, 'text');
        }

        return false;
    }

    /**
     * Question de l'étape (posée de nouveau si la réponse n'est pas comprise).
     */
    private function ask(WhatsappSignup $signup, string $step): bool
    {
        $signup->forceFill(['step' => $step])->save();
        $data = $signup->data ?? [];
        match ($step) {
            'existing' => $this->choices($signup, 'Touche *Me connecter* pour recevoir ton lien de connexion à ta boutique.', ['su:login' => 'Me connecter']),
            'confirm' => $this->choices($signup, 'Tu veux créer ta boutique avec ce numéro : *'.PhoneNumber::display($signup->phone).'* ?', ['su:yes' => '✅ Oui', 'su:other' => 'Autre numéro']),
            'name' => $this->say($signup->phone, "Super ! Quel est le *nom de ta boutique* ?\n_Exemple : Awa Mode_"),
            'first_name' => $this->say($signup->phone, 'Joli nom ! Et *ton prénom* ?'),
            'live' => $this->choices($signup, 'Dernière question : tu vends en *live sur TikTok* ?', ['su:live-yes' => 'Oui, en live', 'su:live-no' => 'Pas encore']),
            'recap' => $this->choices($signup, "Je vérifie avec toi :\n\n🏪 *{$data['store_name']}*\n🔗 ".self::host($this->slugFor($data['store_name'] ?? ''))."\n👤 "
                .($data['first_name'] ?? self::firstName((string) User::where('phone', $signup->phone)->value('name'))).' · '.PhoneNumber::display($signup->phone)
                ."\n🎁 Essai gratuit, sans carte bancaire\n\nOn crée ta boutique ?", ['su:create' => '🚀 Créer', 'su:edit' => 'Modifier']),
            default => null,
        };

        return true;
    }

    private function create(WhatsappSignup $signup): bool
    {
        $data = $signup->data ?? [];
        $phone = $signup->phone;
        $owner = User::where('phone', $phone)->first();
        if ($owner !== null && ! $owner->isActive()) {
            $this->say($phone, 'Ce compte est suspendu. Écris *aide* pour parler à l\'équipe.');

            return true;
        }
        if ($owner !== null && Store::where('owner_id', $owner->id)->where('created_at', '>=', now()->subDay())->count() >= self::MAX_STORES_PER_DAY) {
            $this->say($phone, 'Tu as déjà créé '.self::MAX_STORES_PER_DAY.' boutiques aujourd\'hui. Réessaie demain, ou écris *aide*.');

            return true;
        }

        [$partner, $key] = $this->code($data['code'] ?? null);
        try {
            $store = DB::transaction(function () use ($owner, $phone, $data, $partner) {
                $owner ??= tap((new User)->forceFill([
                    'name' => $data['first_name'] ?? 'Commerçant',
                    'phone' => $phone,
                    // Aucun mot de passe connu : connexion par WhatsApp (ou « Mot de passe oublié »)
                    'password' => Hash::make(Str::random(48)),
                    'status' => UserStatus::Active,
                    'phone_verified_at' => now(),
                ]))->save();

                return app(StoreSignup::class)->createStore($owner, $data['store_name'], $this->slugFor($data['store_name']), partner: $partner);
            });
        } catch (BusinessRuleException $e) {
            $this->say($phone, 'Je n\'ai pas pu créer la boutique : '.$e->getMessage()."\nÉcris un autre nom de boutique.");

            return $this->ask($signup, 'name');
        }
        if ($key !== null) {
            rescue(fn () => app(AccessKeys::class)->redeem($key, $store), report: false);
        }
        $owner = $store->owner;

        // L'accueil habituel ne double pas ce message ; le message « vente en live » seulement pour qui en fait
        OnboardingMessage::firstOrCreate(['store_id' => $store->id, 'step' => 'welcome'], ['user_id' => $owner->id, 'status' => 'sent', 'detail' => 'Inscription par WhatsApp']);
        if (($data['live'] ?? null) === false) {
            OnboardingMessage::firstOrCreate(['store_id' => $store->id, 'step' => 'first_products'], ['user_id' => $owner->id, 'status' => 'skipped', 'detail' => 'Ne fait pas encore de live']);
        }

        $signup->forceFill(['step' => 'photo', 'store_id' => $store->id, 'completed_at' => now()])->save();
        $this->reach($signup, 'created');
        $link = app(LoginHandoff::class)->url($owner, $store, '/gestion', self::LINK_TTL);
        $this->say($phone, '✅ C\'est prêt, '.self::firstName($owner->name)." ! Ta boutique *{$store->name}* est créée.\n\n"
            ."👉 Ouvre-la ici (lien valable 15 min, rien que pour toi, ne le partage pas) :\n{$link}\n\n"
            .'La prochaine fois, connecte-toi avec ton numéro : on t\'envoie la vérification ici, sur WhatsApp.', preview: false);
        $this->say($phone, '📸 Envoie-moi maintenant la *photo d\'un article* que tu vends : je crée ta première fiche produit. (Ou écris *plus tard*.)');

        return true;
    }

    private function photo(WhatsappSignup $signup, string $mediaId): bool
    {
        if ($mediaId === '') {
            // Par QR code, la photo n'arrive pas jusqu'ici
            $this->say($signup->phone, '📸 Merci ! Ajoute-la dans ta boutique, c\'est plus simple : '.$signup->store?->url('/gestion/catalogue/nouveau'));
            $signup->fill(['step' => 'done'])->save();

            return true;
        }
        $signup->fill(['step' => 'done'])->save();
        $this->reach($signup, 'photo');
        $this->say($signup->phone, '👍 Photo reçue, je prépare ta fiche…');
        DraftSignupProduct::dispatch($signup->id, $mediaId);

        return true;
    }

    /**
     * Fiche produit préparée par l'IA depuis la photo reçue (tâche en file : la photo est lue chez Meta).
     */
    /** Photo illisible ou fiche impossible à préparer : lien pour ajouter l'article soi-même */
    public function photoFailed(WhatsappSignup $signup): void
    {
        if ($signup->store === null) {
            return;
        }
        $this->say($signup->phone, 'Je n\'ai pas pu préparer la fiche avec cette photo. Ajoute ton article ici : '.$signup->store->url('/gestion/catalogue/nouveau'));
    }

    public function draftProduct(WhatsappSignup $signup, string $mediaId): void
    {
        $store = $signup->store;
        $owner = $store?->owner;
        if ($store === null || $owner === null) {
            return;
        }
        $bytes = rescue(fn () => $this->whatsapp->media($mediaId), report: true);
        $fallback = fn () => $this->photoFailed($signup);
        if ($bytes === null) {
            $fallback();

            return;
        }

        try {
            $product = app(ProductDrafter::class)->createFromPhoto($store, $owner, $bytes);
        } catch (Throwable $e) {
            report($e);
            $fallback();

            return;
        }
        $options = $product->options->map(fn ($o) => $o->name.' : '.$o->values->pluck('value')->implode(', '))->implode("\n");
        $link = app(LoginHandoff::class)->url($owner, $store, '/gestion/catalogue/'.$product->id, self::LINK_TTL);
        $this->say($signup->phone, "Voilà ta première fiche 👇\n\n*{$product->title}*\nPrix : _à compléter_".($options !== '' ? "\n{$options}" : '')
            ."\n\nAjoute le prix ici (lien 15 min) :\n{$link}\n\nPour ton premier live, écris *live* et je t'explique.", preview: false);
    }

    private function sendLogin(WhatsappSignup $signup): bool
    {
        $store = $signup->store ?? Store::find($signup->store_id);
        $owner = User::where('phone', $signup->phone)->first();
        $signup->fill(['step' => 'idle'])->save();
        if ($store === null || $owner === null || ! $owner->isActive() || $owner->activeMembershipIn($store) === null) {
            $this->say($signup->phone, 'Connecte-toi sur '.Tenancy::platformUrl('/connexion').' avec ton numéro.');

            return true;
        }
        $this->say($signup->phone, "Voici ton lien de connexion (15 min, ne le partage pas) :\n".app(LoginHandoff::class)->url($owner, $store, '/gestion', self::LINK_TTL), preview: false);

        return true;
    }

    private function help(WhatsappSignup $signup): bool
    {
        $signup->forceFill(['paused_until' => now()->addHours(self::PAUSE_HOURS)])->save();
        SupportConversation::whereIn('contact', array_unique([$signup->phone, $this->contact($signup->phone)]))->update(['help_requested_at' => now()]);
        $this->say($signup->phone, 'C\'est noté 🙏 Quelqu\'un de l\'équipe Jibiat Marchand te répond ici très vite.');

        return true;
    }

    /**
     * Mot « live » : le premier live expliqué (phrase clé, 3 étapes, vidéo, lien de connexion vers « Préparer un live »).
     */
    private function live(string $phone): bool
    {
        $owner = User::where('phone', $phone)->first();
        $store = $owner?->stores()->orderBy('stores.id')->first();
        if ($store === null) {
            $this->choices($this->signupRow($phone), self::KEY_PHRASE."\n\nPour vendre en live, il te faut d'abord ta boutique. On la crée maintenant ?", ['su:start' => '🚀 M\'inscrire']);

            return true;
        }
        $this->say($phone, self::KEY_PHRASE."\n\nTon premier live en 3 étapes :\n1️⃣ Connecte ton WhatsApp Business (une fois)\n2️⃣ Choisis tes articles : chacun reçoit un code (A1, A2…)\n3️⃣ Ouvre les commandes et lance ton live sur TikTok : tes clients écrivent le code, la commande est enregistrée\n\n🎬 La vidéo (1 min) : "
            .Tenancy::platformUrl('/videos/live/comment-ca-marche-v.mp4'));
        $this->choices($this->signupRow($phone), 'Je t\'envoie le lien pour préparer ton live ?', ['lv:link' => 'Préparer mon live', 'lv:wa' => 'Connecter WhatsApp', 'su:help' => 'Je veux de l\'aide']);

        return true;
    }

    private function liveLink(string $phone, string $payload): bool
    {
        $owner = User::where('phone', $phone)->first();
        $store = $owner?->stores()->orderBy('stores.id')->first();
        if ($store === null || ! $owner->isActive()) {
            return $this->live($phone);
        }
        $link = app(LoginHandoff::class)->url($owner, $store, '/gestion/live', self::LINK_TTL);
        $this->say($phone, ($payload === 'lv:wa'
            ? "Pour connecter WhatsApp, ton numéro doit être dans l'application *WhatsApp Business* (pas WhatsApp classique). Meta peut te demander de scanner un QR code avec WhatsApp Business : fais-le depuis un autre appareil si tu es sur ce téléphone.\n\n👉 Étape 1 de cette page (lien 15 min) :\n"
            : "👉 Prépare ton live ici (lien 15 min, ne le partage pas) :\n").$link, preview: false);

        return true;
    }

    // ── Outils ──

    private function say(string $phone, string $text, bool $preview = true): void
    {
        try {
            $this->whatsapp->sendAutomatic($this->contact($phone), $text, $preview);
        } catch (RuntimeException) {
            // Envoi impossible (numéro déconnecté, hors des 24 h) : l'équipe voit le message reçu dans la console
        }
    }

    /** @param  array<string, string>  $buttons */
    private function choices(WhatsappSignup $signup, string $text, array $buttons): void
    {
        // Par QR code : la personne répond « 1 », « 2 »… ; on garde la correspondance
        $signup->forceFill(['data' => array_merge($signup->data ?? [], ['choices' => collect(array_keys($buttons))->mapWithKeys(fn ($id, $i) => [(string) ($i + 1) => $id])->all()])])->save();
        try {
            $this->whatsapp->sendChoices($this->contact($signup->phone), $text, $buttons);
        } catch (RuntimeException) {
            // Comme say()
        }
    }

    /** Numéro WhatsApp auquel répondre pour ce compte (celui qui a écrit) */
    private function contact(string $phone): string
    {
        return $this->contacts[$phone] ??= (WhatsappSignup::where('phone', $phone)->first()?->data['wa'] ?? $phone);
    }

    private function signupRow(string $phone): WhatsappSignup
    {
        return WhatsappSignup::firstOrCreate(['phone' => $phone]);
    }

    private function put(WhatsappSignup $signup, array $values): void
    {
        $signup->forceFill(['data' => array_merge($signup->data ?? [], $values)])->save();
    }

    private function reach(WhatsappSignup $signup, string $step): void
    {
        if (($signup->data['misses'] ?? 0) > 0) {
            $signup->forceFill(['data' => array_merge($signup->data, ['misses' => 0])]);
        }
        $signup->forceFill(['reached' => array_merge($signup->reached ?? [], [$step => $signup->reached[$step] ?? now()->toIso8601String()])]);
        if ($signup->exists) {
            $signup->save();
        }
    }

    private function touch(WhatsappSignup $signup): void
    {
        $signup->forceFill(['last_inbound_at' => now()]);
        if ($signup->exists) {
            $signup->save();
        }
    }

    /**
     * Code donné avec « Inscription ABC12 » : clé d'accès (carte) ou entreprise de livraison qui invite.
     *
     * @return array{0: ?string, 1: ?string} [entreprise, clé]
     */
    private function code(?string $code): array
    {
        if (blank($code)) {
            return [null, null];
        }
        $key = rescue(fn () => app(AccessKeys::class)->lookup($code), report: false);
        if (is_array($key) && ($key['available'] ?? false)) {
            return [null, AccessKeys::normalize($code)];
        }
        $partner = rescue(fn () => app(CarrierDirectory::class)->find(Str::lower($code)), report: false);

        return [is_array($partner) ? ($partner['slug'] ?? null) : null, null];
    }

    /**
     * Adresse libre tirée du nom (awa-mode, awa-mode-2…).
     */
    private function slugFor(string $name): string
    {
        $base = Str::limit(trim(Str::slug($name), '-'), 34, '');
        $base = trim($base, '-');
        if (strlen($base) < 3) {
            $base = 'boutique-'.($base !== '' ? $base : Str::lower(Str::random(4)));
        }
        foreach (array_merge([''], range(2, 30)) as $n) {
            $slug = $n === '' ? $base : "{$base}-{$n}";
            if (Validator::make(['slug' => $slug], ['slug' => [new StoreSlug]])->passes()) {
                return $slug;
            }
        }

        return $base.'-'.Str::lower(Str::random(4));
    }

    private static function host(string $slug): string
    {
        return preg_replace('#^https?://#', '', rtrim(Tenancy::storeUrl($slug), '/'));
    }

    /**
     * Pas un nom : moins de 2 lettres, surtout des emojis ou symboles, ou une réponse qui n'en est pas un (« ok »,
     * « bonjour », « Autocollant »…).
     */
    private static function notAName(string $value): bool
    {
        $letters = preg_match_all('/\p{L}/u', $value);
        $symbols = preg_match_all('/[\p{So}\p{Sk}\p{Cs}\p{Co}]/u', $value);

        return $letters < 2 || $symbols > max(2, intdiv($letters, 2)) || in_array(self::words($value), self::NOT_A_NAME, true);
    }

    /**
     * Vocal, autocollant, vidéo… pendant l'inscription : on le dit et on repose la question ; à la troisième réponse
     * incomprise de suite, l'équipe prend la main (robot en pause, message dans la console).
     */
    private function notUnderstood(WhatsappSignup $signup, string $kind): bool
    {
        if ($this->missed($signup)) {
            return true;
        }
        if ($signup->step === 'photo') {
            return $this->photoReminder($signup, $kind);
        }
        $what = match ($kind) {
            'audio' => '🎤 Je ne peux pas écouter les vocaux.',
            'image' => '📷 J\'ai bien reçu ta photo, mais j\'ai d\'abord besoin d\'une réponse écrite.',
            default => 'Je n\'ai pas compris 🙂',
        };
        $this->say($signup->phone, $what.' Réponds par écrit, s\'il te plaît.');

        return $this->ask($signup, $signup->step);
    }

    /**
     * Étape photo, autre chose qu'une photo reçu (vidéo, vocal, texte…) : on dit ce qui est arrivé et comment envoyer
     * une photo, puis la deuxième fois on propose « plus tard » (jamais deux fois la même phrase ; la troisième fois,
     * un conseiller prend la main, voir missed()).
     */
    private function photoReminder(WhatsappSignup $signup, string $kind): bool
    {
        if ((int) ($signup->data['misses'] ?? 1) <= 1) {
            $got = match ($kind) {
                'video' => '🎬 J\'ai reçu une vidéo',
                'audio' => '🎤 J\'ai reçu un vocal',
                'document' => '📄 J\'ai reçu un document',
                'sticker' => 'J\'ai reçu un autocollant',
                default => 'J\'ai bien reçu ton message',
            };
            $this->say($signup->phone, $got.', mais il me faut une *photo* de ton article 📸 : appareil photo › touche une fois (pas d\'appui long). Ou écris *plus tard*.');
        } else {
            $this->say($signup->phone, 'Pas de souci 🙂 Si c\'est compliqué, écris *plus tard* : tu ajouteras tes articles depuis ta boutique.');
        }

        return true;
    }

    /** Réponse incomprise comptée ; true si l'équipe prend la main (le robot se met en pause) */
    private function missed(WhatsappSignup $signup): bool
    {
        $misses = (int) ($signup->data['misses'] ?? 0) + 1;
        if ($misses < self::MAX_MISSES) {
            $this->put($signup, ['misses' => $misses]);

            return false;
        }
        $this->put($signup, ['misses' => 0]);
        $this->say($signup->phone, 'Je passe la main à un conseiller : il te répond ici très vite 🙂');
        $signup->forceFill(['paused_until' => now()->addHours(self::PAUSE_HOURS)])->save();

        return true;
    }

    private function sayTrue(WhatsappSignup $signup, string $text): bool
    {
        $this->say($signup->phone, $text);

        return true;
    }

    private static function nameError(string $name): ?string
    {
        $plain = self::words($name);
        if (mb_strlen(trim($name)) > 60 || self::notAName($name)) {
            return 'Écris le nom de ta boutique (2 à 60 lettres), par exemple *Awa Mode*.';
        }
        if (preg_match('/https?:|www\.|@/i', $name)) {
            return 'Juste le nom, sans lien ni adresse e-mail 🙂';
        }
        foreach (self::FORBIDDEN as $word) {
            if (preg_match('/\b'.$word.'\b/', $plain)) {
                return 'Ce nom n\'est pas possible (« '.$word.' » est réservé). Choisis le nom de ta boutique.';
            }
        }

        return null;
    }

    /**
     * « Inscription », « je veux m'inscrire », « créer ma boutique »… avec un code éventuel après.
     *
     * @return array{code: ?string}|null
     */
    private static function trigger(string $words): ?array
    {
        if (preg_match('/^(?:bonjour |salut |bonsoir )?(?:inscription|inscriptions|inscri|inscrire|s inscrire|m inscrire|je veux m inscrire|je veux minscrire|je m inscris|creer ma boutique|creer une boutique|ouvrir ma boutique|ouvrir une boutique|su start)(?: ([a-z0-9-]{3,20}))?$/', $words, $m)) {
            return ['code' => $m[1] ?? null];
        }

        return null;
    }

    /** Texte simplifié : minuscules, sans accents ni ponctuation */
    private static function words(string $text): string
    {
        $plain = Str::lower(Str::ascii(trim($text)));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9-]+/', ' ', $plain)));
    }

    private static function firstName(?string $name): string
    {
        return Str::before(trim((string) $name), ' ') ?: 'toi';
    }
}
