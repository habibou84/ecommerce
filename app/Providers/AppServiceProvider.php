<?php

namespace App\Providers;

use App\Enums\OrderStatus;
use App\Jobs\AutoAssignOrder;
use App\Mail\GmailApiTransport;
use App\Models\Customer;
use App\Models\InventoryCount;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\StoreTransfer;
use App\Services\Ai\AiClient;
use App\Services\Ai\AnthropicAiClient;
use App\Services\Billing\FreeAccess;
use App\Services\Customers\Loyalty;
use App\Services\Mail\GoogleMail;
use App\Services\Places\Places;
use App\Services\Sms\OrangeSmsGateway;
use App\Services\WhatsApp\CustomerMessenger;
use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Sms\SmsGateway;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Une boutique par requête (réinitialisée entre deux requêtes d'un même processus)
        $this->app->scoped(Tenancy::class);
        // Lieux visibles d'une boutique : mis en cache le temps de la requête
        $this->app->scoped(Places::class);
        // Gratuité donnée depuis la console : réglages lus une fois par requête
        $this->app->scoped(FreeAccess::class);
        // Assistant IA (Claude) ; remplacé par un faux dans les tests
        $this->app->singleton(AiClient::class, fn () => new AnthropicAiClient(config('services.anthropic.key'), (string) config('services.anthropic.model')));

        // SMS par Orange (SMS_DRIVER=orange) ; les autres pilotes (log, twilio, none) viennent du paquet socle
        if (config('services.sms.driver') === 'orange') {
            $this->app->singleton(SmsGateway::class, fn () => new OrangeSmsGateway(
                (string) config('services.sms.orange.client_id'),
                (string) config('services.sms.orange.client_secret'),
                (string) config('services.sms.orange.sender_address'),
                config('services.sms.orange.sender_name') ?: null,
            ));
        }
    }

    public function boot(): void
    {
        // E-mails envoyés par le compte Gmail connecté avec Google dans la console (API Gmail, sans mot de passe d'application)
        Mail::extend('gmail_api', fn () => new GmailApiTransport(app(GoogleMail::class)));

        // Messages WhatsApp automatiques aux clients (confirmation, expédition, livraison), une fois l'opération validée
        OrderEvent::created(fn (OrderEvent $event) => DB::afterCommit(fn () => app(CustomerMessenger::class)->onOrderEvent($event)));
        // Fidélité : points gagnés à la livraison, rendus ou retirés si la commande est annulée ou retournée
        OrderEvent::created(fn (OrderEvent $event) => app(Loyalty::class)->onOrderEvent($event));
        // Dès la confirmation : expédition confiée à qui a été choisi à la saisie, sinon (mode « auto ») à qui
        // désignent les règles
        OrderEvent::created(function (OrderEvent $event) {
            if ($event->type !== 'status_changed' || ($event->data['to'] ?? null) !== OrderStatus::Confirmed->value) {
                return;
            }
            $planned = Order::withoutGlobalScopes()->whereKey($event->order_id)
                ->where(fn ($q) => $q->whereNotNull('planned_courier_id')->orWhereNotNull('planned_carrier_connection_id'))->exists();
            if ($planned || Store::whereKey($event->store_id)->value('assignment_mode') === 'auto') {
                DB::afterCommit(fn () => AutoAssignOrder::dispatch($event->order_id));
            }
        });

        // Sonde /up : la base de données doit répondre (supervision de la disponibilité)
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // Montant dans les gabarits : @money($order->total) → « 12 500 F »
        Blade::directive('money', fn (string $expression) => "<?php echo e(\\Jibiat\\Socle\\Money::format((int) ({$expression}))); ?>");

        // Noms courts dans les colonnes polymorphes (étiquettes, références du journal de stock)
        Relation::morphMap([
            'product' => Product::class,
            'stock_receipt' => StockReceipt::class,
            'stock_transfer' => StockTransfer::class,
            'store_transfer' => StoreTransfer::class,
            'inventory_count' => InventoryCount::class,
            'order' => Order::class,
            'customer' => Customer::class,
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-phone:'.PhoneNumber::normalize((string) $request->input('phone'))),
            // Plafond par heure et par numéro : on ne teste pas des milliers de mots de passe sur un compte
            Limit::perHour(30)->by('login-phone-hour:'.PhoneNumber::normalize((string) $request->input('phone'))),
        ]);

        // Ouverture d'une vérification par SMS (création de boutique, mot de passe oublié)
        RateLimiter::for('verification-start', fn (Request $request) => Limit::perHour(10)->by('verification-start:'.$request->ip()));

        // Boutique en ligne : appels JSON (panier, quartier, frais) et passage de commande
        RateLimiter::for('storefront', fn (Request $request) => Limit::perMinute(120)->by('storefront:'.$request->ip()));
        // Numéro laissé au moment de commander (relance du panier) : pas des milliers de numéros depuis une même adresse
        // Codes promo : quelques essais par minute, pour qu'on ne devine pas les codes privés en les essayant
        RateLimiter::for('storefront-code', fn (Request $request) => Limit::perMinute(10)->by('storefront-code:'.$request->ip()));
        RateLimiter::for('storefront-contact', fn (Request $request) => [
            Limit::perMinute(10)->by('storefront-contact:'.$request->ip()),
            Limit::perHour(60)->by('storefront-contact-hour:'.$request->ip()),
        ]);
        RateLimiter::for('storefront-order', fn (Request $request) => [
            Limit::perMinute(5)->by('storefront-order:'.$request->ip()),
            Limit::perHour(30)->by('storefront-order-hour:'.$request->ip()),
        ]);

        RateLimiter::for('carrier-webhooks', fn (Request $request) => Limit::perMinute(600)->by('carrier-webhooks:'.$request->route('uuid')));

        RateLimiter::for('verification-code', fn (Request $request) => Limit::perMinute(10)->by('verification-code:'.$request->ip()));
    }
}
