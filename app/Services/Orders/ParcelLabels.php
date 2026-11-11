<?php

namespace App\Services\Orders;

use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\ParcelLabel;
use App\Models\ParcelLabelBatch;
use App\Models\Store;
use App\Models\User;
use App\Services\Platform\Features;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Étiquettes pré-imprimées : lots à imprimer, code unique par étiquette, étiquette reliée à une commande en la
 * scannant (ou en tapant son code). Chaque format s'allume dans la console (Fonctions) ; une étiquette déjà collée
 * reste lisible même si la fonction est éteinte ensuite.
 */
class ParcelLabels
{
    /** Sans 0/O, 1/I/L ni U/V proches : lisible à l'œil et tapé sans erreur */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTWXYZ';

    public function __construct(private readonly Features $features, private readonly OrderDesk $desk, private readonly OrderDocuments $documents) {}

    /**
     * Formats que la boutique peut imprimer (selon la console).
     *
     * @return list<string>
     */
    public function formats(Store $store): array
    {
        return array_values(array_merge(
            $this->features->enabled('labels_mini', $store) ? ['mini', 'sheet'] : [],
            $this->features->enabled('labels_large', $store) ? ['large'] : [],
        ));
    }

    public function enabled(Store $store): bool
    {
        return $this->formats($store) !== [];
    }

    /** @return array<string, mixed> */
    public function overview(Store $store): array
    {
        $labels = ParcelLabel::forStore($store->id);

        return [
            'formats' => collect($this->formats($store))->map(fn ($f) => ['code' => $f, 'label' => ParcelLabelBatch::FORMATS[$f]])->values(),
            'quantities' => ParcelLabelBatch::QUANTITIES,
            'free' => (clone $labels)->whereNull('order_id')->count(),
            'used' => (clone $labels)->whereNotNull('order_id')->count(),
            'batches' => ParcelLabelBatch::forStore($store->id)->latest('id')->limit(10)->withCount(['labels as used_count' => fn ($q) => $q->whereNotNull('order_id')])->get()
                ->map(fn (ParcelLabelBatch $b) => ['id' => $b->id, 'format' => $b->format, 'format_label' => ParcelLabelBatch::FORMATS[$b->format] ?? $b->format,
                    'quantity' => $b->quantity, 'used' => (int) $b->used_count, 'created_at' => $b->created_at?->toIso8601String()])->values(),
        ];
    }

    public function createBatch(Store $store, ?User $user, string $format, int $quantity): ParcelLabelBatch
    {
        if (! in_array($format, $this->formats($store), true)) {
            throw new BusinessRuleException('Ce format d\'étiquettes n\'est pas disponible.', 'format');
        }
        if (! in_array($quantity, ParcelLabelBatch::QUANTITIES, true)) {
            throw new BusinessRuleException('Nombre d\'étiquettes non proposé.', 'quantity');
        }

        return DB::transaction(function () use ($store, $user, $format, $quantity) {
            $batch = ParcelLabelBatch::create(['store_id' => $store->id, 'format' => $format, 'quantity' => $quantity, 'created_by' => $user?->id]);
            $now = now();
            $codes = [];
            while (count($codes) < $quantity) {
                $codes[$this->randomCode()] = true;
            }
            // Un code déjà pris par une autre boutique (très rare) est remplacé
            foreach (ParcelLabel::withoutGlobalScopes()->whereIn('code', array_keys($codes))->pluck('code') as $taken) {
                unset($codes[$taken]);
                do {
                    $code = $this->randomCode();
                } while (isset($codes[$code]) || ParcelLabel::withoutGlobalScopes()->where('code', $code)->exists());
                $codes[$code] = true;
            }
            foreach (array_chunk(array_keys($codes), 200) as $chunk) {
                ParcelLabel::insert(array_map(fn ($code) => ['store_id' => $store->id, 'batch_id' => $batch->id, 'code' => $code, 'created_at' => $now, 'updated_at' => $now], $chunk));
            }

            return $batch;
        });
    }

    /** PDF du lot : une étiquette par page (rouleau) ou 45 mini étiquettes par feuille A4 */
    public function pdf(ParcelLabelBatch $batch): Response
    {
        // 500 étiquettes : jusqu'à une quinzaine de secondes de mise en page
        @set_time_limit(120);
        $store = $batch->store;
        $labels = $batch->labels()->orderBy('id')->get()->map(fn (ParcelLabel $l) => [
            'code' => $l->display(),
            'qr' => $this->documents->qr($store->url('/e/'.$l->code)),
        ])->all();
        // Tailles en points (1 mm = 2,8346 pt)
        $paper = match ($batch->format) {
            'mini' => [0, 0, 113.39, 85.04],
            'large' => [0, 0, 283.46, 425.2],
            default => 'a4',
        };

        return Pdf::loadView('pdf.parcel-labels', ['labels' => $labels, 'format' => $batch->format, 'store' => $store])
            ->setPaper($paper)->download('etiquettes-'.$batch->format.'-'.$batch->id.'.pdf');
    }

    /**
     * Code lu par la caméra ou tapé : lien complet, « JM-7K4Q-29XB » ou « 7k4q29xb ».
     */
    public static function normalize(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('#/e/([A-Za-z0-9]{8})(?:[/?\#]|$)#', $input, $m)) {
            return strtoupper($m[1]);
        }
        $raw = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
        if (strlen($raw) === 10 && str_starts_with($raw, 'JM')) {
            $raw = substr($raw, 2);
        }

        return strlen($raw) === 8 ? $raw : null;
    }

    public function attach(Order $order, string $input, ?User $user): ParcelLabel
    {
        $code = self::normalize($input);
        if ($code === null) {
            throw new BusinessRuleException('Ce n\'est pas un code d\'étiquette (exemple : JM-7K4Q-29XB).', 'code');
        }

        return DB::transaction(function () use ($order, $code, $user) {
            $label = ParcelLabel::forStore($order->store_id)->where('code', $code)->lockForUpdate()->first();
            if ($label === null) {
                throw new BusinessRuleException('Cette étiquette n\'est pas une étiquette de ta boutique.', 'code');
            }
            if ($label->order_id === $order->id) {
                return $label;
            }
            if ($label->order_id !== null) {
                $other = Order::forStore($order->store_id)->find($label->order_id);
                throw new BusinessRuleException('L\'étiquette '.$label->display().' est déjà sur la commande '.($other?->reference() ?? '').'. Rien n\'a été changé.', 'code');
            }
            $label->update(['order_id' => $order->id, 'assigned_at' => now(), 'assigned_by' => $user?->id]);
            $this->desk->log($order, 'parcel_label', ['code' => $label->display()], $user);

            return $label;
        });
    }

    public function detach(Order $order, ParcelLabel $label, ?User $user): void
    {
        if ($label->order_id !== $order->id) {
            throw new BusinessRuleException('Cette étiquette n\'est pas sur cette commande.');
        }
        $label->update(['order_id' => null, 'assigned_at' => null, 'assigned_by' => null]);
        $this->desk->log($order, 'parcel_label_removed', ['code' => $label->display()], $user);
    }

    /**
     * Étiquettes d'une commande, pour sa fiche.
     *
     * @return list<array{id: int, code: string}>
     */
    public function forOrder(Order $order): array
    {
        return ParcelLabel::forStore($order->store_id)->where('order_id', $order->id)->orderBy('assigned_at')->get()
            ->map(fn (ParcelLabel $l) => ['id' => $l->id, 'code' => $l->display()])->values()->all();
    }

    private function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
