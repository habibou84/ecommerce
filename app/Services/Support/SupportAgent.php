<?php

namespace App\Services\Support;

use App\Jobs\AnswerSupportMessage;
use App\Models\HelpArticle;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupportAgentRun;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsappSignup;
use App\Services\Accounts\SetupGuide;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\Help\HelpCenter;
use App\Services\WhatsApp\PlatformWhatsApp;
use App\Services\WhatsApp\SignupByWhatsApp;
use App\Services\WhatsApp\SupportAlerts;
use App\Support\StartSteps;
use App\Support\Tenancy;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;
use Throwable;

/**
 * Assistant du support sur WhatsApp : il répond aux questions libres des marchands sur le numéro du support, à partir
 * des fiches du centre d'aide et de l'état de leur boutique, et passe la main à l'équipe dès qu'il ne sait pas.
 *
 * Garde-fous :
 * - désactivé par défaut ; seulement avec la connexion officielle de Meta (pas par QR code) ;
 * - il attend 20 s après le dernier message (la personne a fini d'écrire) et ne répond qu'au dernier ;
 * - il se tait quand l'inscription par WhatsApp est en cours, quand l'équipe a répondu depuis moins de 12 h ou
 *   quand « Aide demandée » est levée sur la conversation ;
 * - une réponse vient d'une fiche ou des données du compte, jamais d'une invention : sinon il passe la main ;
 * - plafond de coût par jour, et au plus 20 réponses par personne et par jour.
 */
class SupportAgent
{
    public const WAIT_SECONDS = 20;

    public const HUMAN_HOURS = 12;

    public const MAX_PER_DAY = 20;

    public const DEFAULT_DAILY_BUDGET = 3.5;

    private const HISTORY = 12;

    public function __construct(
        private readonly AiClient $client,
        private readonly PlatformWhatsApp $whatsapp,
        private readonly HelpCenter $help,
        private readonly SupportAlerts $alerts,
        private readonly SetupGuide $guide,
    ) {}

    /**
     * @return array{enabled: bool, daily_budget: float, available: bool, unavailable_reason: ?string}
     */
    public function settings(): array
    {
        $saved = PlatformSetting::group('support_agent');
        $reason = match (true) {
            ! $this->client->configured() => 'L\'assistant IA n\'est pas configuré (clé absente).',
            ! $this->whatsapp->connected() => 'Le WhatsApp du support n\'est pas connecté.',
            $this->whatsapp->isQr() => 'Le WhatsApp du support est relié par QR code : l\'assistant ne répond qu\'avec la connexion officielle de Meta.',
            default => null,
        };

        return [
            'enabled' => ($saved['enabled'] ?? '0') === '1',
            'daily_budget' => (float) ($saved['daily_budget'] ?? self::DEFAULT_DAILY_BUDGET),
            'available' => $reason === null,
            'unavailable_reason' => $reason,
        ];
    }

    public function update(bool $enabled, float $dailyBudget): void
    {
        PlatformSetting::put('support_agent.enabled', $enabled ? '1' : '0');
        PlatformSetting::put('support_agent.daily_budget', (string) round($dailyBudget, 2));
    }

    /**
     * Message reçu sur le numéro du support, auquel aucun robot n'a répondu : l'assistant y répondra dans 20 s.
     */
    public function schedule(SupportMessage $message): void
    {
        $settings = $this->settings();
        if ($settings['enabled'] && $settings['available'] && $message->direction === 'in' && $message->type === 'text') {
            AnswerSupportMessage::dispatch($message->id)->delay(now()->addSeconds(self::WAIT_SECONDS));
        }
    }

    /**
     * Répond au message s'il est toujours le dernier de la conversation. Renvoie ce qui a été fait (null : rien).
     */
    public function run(int $messageId): ?string
    {
        $message = SupportMessage::with('conversation')->find($messageId);
        $conversation = $message?->conversation;
        $settings = $this->settings();
        if ($conversation === null || ! $settings['enabled'] || ! $settings['available'] || ! $this->mayAnswer($conversation, $message)) {
            return null;
        }
        if ($this->spentToday() >= $settings['daily_budget']) {
            // Une seule ligne par conversation et par jour : l'équipe répond, comme avant l'assistant
            if (! SupportAgentRun::where('support_conversation_id', $conversation->id)->where('outcome', SupportAgentRun::BUDGET)->whereDate('created_at', today())->exists()) {
                $this->record($conversation, $message, SupportAgentRun::BUDGET, 'Plafond du jour atteint');
            }

            return SupportAgentRun::BUDGET;
        }

        $user = $this->userFor($conversation->contact);
        $store = $user ? $this->storeFor($user) : null;
        $articles = [];
        $handoff = null;
        $run = function (string $name, array $input) use ($user, $store, &$articles, &$handoff) {
            return match ($name) {
                'chercher_aide' => $this->searchHelp((string) ($input['question'] ?? ''), $articles),
                'ma_boutique' => $this->storeState($user, $store),
                'passer_a_un_humain' => (function () use ($input, &$handoff) {
                    $handoff = Str::limit(trim((string) ($input['raison'] ?? '')) ?: 'Demande d\'aide', 190);

                    return ['fait' => true, 'consigne' => 'L\'équipe est prévenue. Dites à la personne, en une phrase, qu\'un membre de l\'équipe lui répond ici bientôt.'];
                })(),
                default => ['erreur' => 'Outil inconnu.'],
            };
        };

        try {
            $result = $this->client->agent($this->system($user, $store), $this->transcript($conversation), $this->tools(), $run, 5);
        } catch (AiUnavailable $e) {
            $this->record($conversation, $message, SupportAgentRun::FAILED, Str::limit($e->getMessage(), 190), [], $e->inputTokens, $e->outputTokens);
            $this->alerts->helpRequested($conversation->contact, 'L\'assistant n\'a pas pu répondre');

            return SupportAgentRun::FAILED;
        }

        // Entre-temps, la personne a réécrit ou l'équipe a répondu : cette réponse n'est plus la bonne
        if (! $this->mayAnswer($conversation->fresh(), $message)) {
            $this->record($conversation, $message, 'skipped', 'Conversation reprise pendant la réponse', $articles, $result['input_tokens'], $result['output_tokens']);

            return null;
        }
        try {
            $this->whatsapp->sendAutomatic($conversation->contact, Str::limit($result['text'], 3900));
        } catch (Throwable $e) {
            report($e);
            $this->record($conversation, $message, SupportAgentRun::FAILED, 'Réponse non envoyée', $articles, $result['input_tokens'], $result['output_tokens']);
            $this->alerts->helpRequested($conversation->contact, 'La réponse de l\'assistant n\'est pas partie');

            return SupportAgentRun::FAILED;
        }
        $outcome = $handoff !== null ? SupportAgentRun::HANDOFF : SupportAgentRun::ANSWERED;
        $this->record($conversation, $message, $outcome, $handoff, $articles, $result['input_tokens'], $result['output_tokens']);
        if ($handoff !== null) {
            $this->alerts->helpRequested($conversation->contact, $handoff);
        }

        return $outcome;
    }

    /**
     * Suivi pour la console : les 7 derniers jours et aujourd'hui.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $week = SupportAgentRun::where('created_at', '>=', now()->subDays(7))->get(['outcome', 'cost_usd', 'created_at']);
        $answered = $week->where('outcome', SupportAgentRun::ANSWERED)->count();
        $handoff = $week->where('outcome', SupportAgentRun::HANDOFF)->count();

        return [
            'spent_today' => round($this->spentToday(), 2),
            'week' => [
                'answered' => $answered,
                'handoff' => $handoff,
                'failed' => $week->where('outcome', SupportAgentRun::FAILED)->count(),
                'budget' => $week->where('outcome', SupportAgentRun::BUDGET)->count(),
                'alone_percent' => $answered + $handoff > 0 ? (int) round(100 * $answered / ($answered + $handoff)) : null,
                'cost_usd' => round((float) $week->sum('cost_usd'), 2),
            ],
            'handoffs' => SupportAgentRun::with('conversation:id,contact,contact_name')->where('outcome', SupportAgentRun::HANDOFF)->latest('id')->limit(20)->get()
                ->map(fn (SupportAgentRun $r) => [
                    'id' => $r->id, 'reason' => $r->reason, 'at' => $r->created_at,
                    'contact' => $r->conversation?->contact_name ?: PhoneNumber::display((string) $r->conversation?->contact),
                ])->values(),
        ];
    }

    private function mayAnswer(SupportConversation $conversation, SupportMessage $message): bool
    {
        // Seulement le dernier message : une suite de messages reçoit une seule réponse, et rien après une réponse
        if ($conversation->messages()->where('id', '>', $message->id)->exists() || $conversation->help_requested_at !== null) {
            return false;
        }
        // L'équipe a la main : réponse de la console ou du téléphone depuis moins de 12 h
        if ($conversation->messages()->where('direction', 'out')->where('type', '!=', 'automatic')->where('sent_at', '>=', now()->subHours(self::HUMAN_HOURS))->exists()) {
            return false;
        }
        // Inscription par WhatsApp en cours : son robot pose les questions
        $signup = WhatsappSignup::where('phone', PhoneNumber::modernize($conversation->contact))->first();
        if ($signup !== null && in_array($signup->step, SignupByWhatsApp::ACTIVE, true)) {
            return false;
        }

        return SupportAgentRun::where('support_conversation_id', $conversation->id)->whereIn('outcome', [SupportAgentRun::ANSWERED, SupportAgentRun::HANDOFF])
            ->whereDate('created_at', today())->count() < self::MAX_PER_DAY;
    }

    private function spentToday(): float
    {
        return (float) SupportAgentRun::whereDate('created_at', today())->sum('cost_usd');
    }

    private function record(SupportConversation $conversation, SupportMessage $message, string $outcome, ?string $reason, array $articles = [], int $input = 0, int $output = 0): void
    {
        $model = $this->client->model();
        // Modèle sans prix connu : au prix du plus cher, pour ne jamais sous-estimer
        [$in, $out] = config('services.anthropic.prices')[$model] ?? collect(config('services.anthropic.prices'))->sortByDesc(fn ($p) => $p[1])->first();
        SupportAgentRun::create([
            'support_conversation_id' => $conversation->id, 'support_message_id' => $message->id, 'outcome' => $outcome, 'reason' => $reason,
            'articles' => array_values(array_unique($articles)), 'model' => $model, 'input_tokens' => $input, 'output_tokens' => $output,
            'cost_usd' => ($input * $in + $output * $out) / 1_000_000,
        ]);
    }

    private function userFor(string $contact): ?User
    {
        $phones = array_values(array_unique(array_filter([PhoneNumber::modernize($contact), PhoneNumber::normalize($contact)])));

        return $phones === [] ? null : User::whereIn('phone', $phones)->first();
    }

    private function storeFor(User $user): ?Store
    {
        return Store::where('owner_id', $user->id)->oldest('id')->first() ?? $user->stores()->oldest('stores.id')->first();
    }

    /**
     * @param  list<string>  $articles  fiches trouvées (pour le suivi)
     * @return array<string, mixed>
     */
    private function searchHelp(string $question, array &$articles): array
    {
        $found = $this->help->search($question, 4);
        if ($found->isEmpty()) {
            return ['fiches' => [], 'consigne' => 'Aucune fiche ne répond. Essayez d\'autres mots une fois ; sinon passez la main à un humain, sans inventer.'];
        }

        return ['fiches' => $found->map(function (HelpArticle $a) use (&$articles) {
            $articles[] = $a->slug;
            $shown = $this->help->present($a);

            return array_filter([
                'question' => $a->title,
                'reponse' => $a->summary,
                'etapes' => $a->steps ?? [],
                'details' => $a->body,
                'video' => $this->videoLink($a, $shown['video']['url'] ?? null),
            ]);
        })->all()];
    }

    /**
     * Lien de la vidéo à envoyer : la page du site pour une étape essentielle (elle se lit dans WhatsApp), sinon le fichier.
     */
    private function videoLink(HelpArticle $a, ?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $step = collect(StartSteps::all())->first(fn (array $s) => strtok($s['video'], '?') === strtok($url, '?'));
        if ($step !== null) {
            return Tenancy::platformUrl(sprintf('/video/etapes/etape-%02d', $step['n']));
        }

        return str_starts_with($url, '/') ? Tenancy::platformUrl($url) : $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function storeState(?User $user, ?Store $store): array
    {
        if ($user === null || $store === null) {
            return ['compte' => false, 'consigne' => 'Ce numéro n\'a pas encore d\'espace marchand. Pour en créer un : écrire « Inscription » dans cette discussion.'];
        }
        $guide = $this->guide->forUser($store, $user);
        $next = collect($guide['steps'])->firstWhere('key', $guide['next']);

        return [
            'compte' => true,
            'prenom' => Str::before((string) $user->name, ' '),
            'boutique' => $store->name,
            'adresse' => $store->url('/'),
            'gestion' => $store->url('/gestion'),
            'produits' => Product::forStore($store->id)->where('status', 'active')->count(),
            'guide_de_demarrage' => [
                'etapes_faites' => $guide['done'],
                'etapes_essentielles' => $guide['total'],
                'a_faire' => collect($guide['steps'])->where('done', false)->where('optional', false)->pluck('title')->values()->all(),
                'prochaine_etape' => $next ? ['titre' => $next['title'], 'pourquoi' => $next['why'], 'quoi_faire' => $next['todo'], 'ecran' => $store->url($next['to'])] : null,
            ],
        ];
    }

    /**
     * Les derniers messages de la conversation, du plus ancien au plus récent.
     */
    private function transcript(SupportConversation $conversation): string
    {
        $lines = $conversation->messages()->latest('id')->limit(self::HISTORY)->get()->reverse()
            ->map(fn (SupportMessage $m) => ($m->direction === 'in' ? 'Marchand' : ($m->type === 'automatic' ? 'Assistant' : 'Équipe')).' : '.Str::limit((string) $m->body, 1200))
            ->implode("\n");

        return "Voici la discussion WhatsApp, du plus ancien au plus récent. Répondez au dernier message du marchand.\n\n".$lines;
    }

    private function system(?User $user, ?Store $store): string
    {
        $who = $store !== null
            ? 'La personne qui écrit a un espace marchand : « '.$store->name.' ».'
            : 'La personne qui écrit n\'a pas encore d\'espace marchand à ce numéro (un futur marchand, peut-être).';

        return <<<TXT
        Vous êtes l'assistant du support de Jibiat Marchand, sur WhatsApp. Jibiat Marchand est l'application des e-commerçants de Côte d'Ivoire : boutique en ligne, commandes, stock, livreurs, vente en live, messages WhatsApp aux clients. Les marchands vous écrivent depuis leur téléphone, souvent entre deux ventes : ils veulent une réponse courte qui les débloque tout de suite.

        {$who}

        Comment répondre
        - Cherchez d'abord la réponse avec l'outil chercher_aide, avec les mots du marchand. Si la question porte sur sa boutique ou sur ce qu'il lui reste à faire, regardez aussi ma_boutique.
        - Répondez seulement avec ce que disent les fiches et les données du compte. L'application change souvent : un chemin de menu ou un bouton deviné enverrait le marchand au mauvais endroit. Si les fiches ne répondent pas, passez la main à l'équipe avec passer_a_un_humain plutôt que de deviner.
        - Passez aussi la main quand il s'agit d'argent (paiement, remboursement, facture, abonnement), d'un problème technique (erreur, compte bloqué, écran qui ne marche pas), d'un changement de numéro ou de propriétaire, quand la personne est mécontente, ou quand elle demande une personne. Dans ce cas, dites-lui en une phrase qu'un membre de l'équipe lui répond ici.
        - Ne demandez jamais de mot de passe ni de code, et n'en donnez jamais.

        La forme
        - Vouvoyez. Français simple et chaleureux, phrases courtes. Le marchand peut écrire en nouchi ou avec des fautes : comprenez-le, et répondez en français simple.
        - Un message WhatsApp : 2 à 6 lignes. Pour une marche à suivre, numérotez les étapes (1. 2. 3.), une par ligne. Pas de titres ni de tableaux ; le gras s'écrit *comme ceci*.
        - Quand une fiche a une vidéo, donnez son lien sur une ligne à part, à la fin.
        - Écrivez les noms des menus et des boutons exactement comme dans les fiches, entre « ».
        - Une salutation ou un merci : répondez en une ligne, sans outil.
        TXT;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'name' => 'chercher_aide',
                'description' => 'Cherche dans le centre d\'aide de Jibiat Marchand les fiches qui répondent à une question. Renvoie pour chaque fiche la question, la réponse courte, les étapes à suivre et, s\'il y en a une, le lien de la vidéo. À appeler pour toute question sur l\'utilisation de l\'application, avec des mots simples (par exemple « ajouter un livreur », « frais de livraison », « mot de passe oublié »).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['question' => ['type' => 'string', 'description' => 'La question, avec les mots du marchand']],
                    'required' => ['question'],
                ],
            ],
            [
                'name' => 'ma_boutique',
                'description' => 'État de l\'espace marchand de la personne qui écrit : nom de la boutique, adresses, nombre de produits, avancement du guide de démarrage et prochaine étape à faire. Dit aussi si ce numéro n\'a pas encore d\'espace marchand. À appeler quand la question porte sur sa boutique (« où j\'en suis », « quoi faire maintenant », « c\'est quoi mon lien »).',
                // Au moins un paramètre obligatoire : le SDK PHP enverrait un objet vide comme une liste ([])
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['sujet' => ['type' => 'string', 'description' => 'Ce que vous cherchez à savoir, en quelques mots']],
                    'required' => ['sujet'],
                ],
            ],
            [
                'name' => 'passer_a_un_humain',
                'description' => 'Prévient l\'équipe du support qu\'une personne doit lui répondre dans cette discussion. À appeler quand les fiches ne répondent pas, pour l\'argent, un problème technique, une personne mécontente, ou quand elle demande à parler à quelqu\'un.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['raison' => ['type' => 'string', 'description' => 'Résumé pour l\'équipe, en une phrase : qui, sa question, ce qui a déjà été essayé']],
                    'required' => ['raison'],
                ],
            ],
        ];
    }
}
