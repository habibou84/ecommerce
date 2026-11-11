<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ParcelLabel;
use App\Models\ParcelLabelBatch;
use App\Services\Orders\ParcelLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Étiquettes pré-imprimées : lots à imprimer (Ventes › Étiquettes), étiquette scannée et reliée à une commande.
 */
class ParcelLabelController extends Controller
{
    public function __construct(private readonly ParcelLabels $labels) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->labels->overview($this->currentStore())]);
    }

    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate(['format' => ['required', 'string', 'max:10'], 'quantity' => ['required', 'integer']]);
        $batch = $this->labels->createBatch($this->currentStore(), $request->user(), $data['format'], (int) $data['quantity']);

        return response()->json(['data' => ['id' => $batch->id, 'overview' => $this->labels->overview($this->currentStore())]], 201);
    }

    /** Colis scanné dans la gestion : la commande de l'étiquette (null si elle n'est pas encore utilisée) */
    public function lookup(Request $request): JsonResponse
    {
        $code = ParcelLabels::normalize((string) $request->query('code', ''));
        $label = $code ? ParcelLabel::where('code', $code)->first() : null;
        if ($label === null) {
            throw new BusinessRuleException('Cette étiquette n\'est pas une étiquette de ta boutique.', 'code');
        }

        return response()->json(['data' => ['code' => $label->display(), 'order_id' => $label->order_id]]);
    }

    public function pdf(int $batch): Response
    {
        return $this->labels->pdf(ParcelLabelBatch::findOrFail($batch));
    }

    public function attach(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:200']]);
        $model = Order::findOrFail($order);
        if (! $this->labels->enabled($this->currentStore())) {
            throw new BusinessRuleException('Les étiquettes à scanner ne sont pas disponibles.');
        }
        $label = $this->labels->attach($model, $data['code'], $request->user());

        return response()->json(['data' => ['code' => $label->display(), 'labels' => $this->labels->forOrder($model)]]);
    }

    public function detach(Request $request, int $order, int $label): JsonResponse
    {
        $model = Order::findOrFail($order);
        $this->labels->detach($model, ParcelLabel::findOrFail($label), $request->user());

        return response()->json(['data' => ['labels' => $this->labels->forOrder($model)]]);
    }
}
