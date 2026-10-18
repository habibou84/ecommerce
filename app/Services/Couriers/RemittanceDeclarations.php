<?php

namespace App\Services\Couriers;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\CourierRemittanceDeclaration;
use App\Models\User;
use App\Notifications\RemittanceDecided;
use App\Notifications\RemittanceDeclared;
use App\Services\Accounts\TeamNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Versement déclaré par le livreur : il dit ce qu'il a remis (espèces à la caisse, ou envoi Wave / mobile money
 * avec la référence) ; la boutique confirme d'un geste, et le versement est enregistré avec son écart, ou refuse.
 */
class RemittanceDeclarations
{
    public function __construct(
        private readonly CashDesk $cash,
        private readonly SharedCouriers $shared,
        private readonly TeamNotifier $team,
    ) {}

    /**
     * @param  array{amount: int, method: string, reference?: ?string, note?: ?string}  $data
     */
    public function declare(Courier $courier, array $data, ?UploadedFile $photo = null): CourierRemittanceDeclaration
    {
        $held = $this->shared->held($courier);
        if ($held <= 0) {
            throw new BusinessRuleException('Vous n\'avez pas d\'espèces à verser à cette boutique.');
        }
        if (CourierRemittanceDeclaration::forStore($courier->store_id)->where('courier_id', $courier->id)->where('status', 'pending')->exists()) {
            throw new BusinessRuleException('Un versement attend déjà la confirmation de la boutique.');
        }
        if ($data['method'] !== 'cash' && trim((string) ($data['reference'] ?? '')) === '') {
            throw new BusinessRuleException('Indiquez la référence de l\'envoi (dans le SMS de confirmation).', 'reference');
        }

        $declaration = CourierRemittanceDeclaration::create([
            'store_id' => $courier->store_id,
            'courier_id' => $courier->id,
            'amount' => $data['amount'],
            'method' => $data['method'],
            'reference' => trim((string) ($data['reference'] ?? '')) ?: null,
            'note' => $data['note'] ?? null,
            'proof_path' => $photo?->store("remittances/{$courier->store_id}", 'local'),
            'held_amount' => $held,
        ]);

        $this->team->notify($courier->store, Permission::CouriersManage->value, new RemittanceDeclared($declaration->setRelation('courier', $courier)));

        return $declaration;
    }

    /**
     * La boutique a bien reçu l'argent : versement enregistré (montant reçu, sinon celui déclaré), écart gardé.
     */
    public function confirm(CourierRemittanceDeclaration $declaration, ?int $received, User $by): CourierRemittanceDeclaration
    {
        $declaration = DB::transaction(function () use ($declaration, $received, $by) {
            $declaration = CourierRemittanceDeclaration::forStore($declaration->store_id)->lockForUpdate()->with('courier')->findOrFail($declaration->id);
            $this->ensurePending($declaration);

            $note = 'Déclaré par le livreur'.($declaration->reference ? " · réf. {$declaration->reference}" : '').($declaration->note ? " · {$declaration->note}" : '');
            $remittance = $this->cash->remit($declaration->courier, $received ?? $declaration->amount, $declaration->method, mb_substr($note, 0, 255), $by);

            $declaration->forceFill(['status' => 'confirmed', 'remittance_id' => $remittance->id, 'decided_by' => $by->id, 'decided_at' => now()])->save();

            return $declaration->setRelation('remittance', $remittance);
        });

        $declaration->courier->user?->notify(new RemittanceDecided($declaration));

        return $declaration;
    }

    public function reject(CourierRemittanceDeclaration $declaration, string $reason, User $by): CourierRemittanceDeclaration
    {
        $this->ensurePending($declaration);
        $declaration->forceFill(['status' => 'rejected', 'reject_reason' => $reason, 'decided_by' => $by->id, 'decided_at' => now()])->save();
        $declaration->courier->user?->notify(new RemittanceDecided($declaration));

        return $declaration;
    }

    private function ensurePending(CourierRemittanceDeclaration $declaration): void
    {
        if ($declaration->status !== 'pending') {
            throw new BusinessRuleException('Ce versement a déjà été traité.');
        }
    }
}
