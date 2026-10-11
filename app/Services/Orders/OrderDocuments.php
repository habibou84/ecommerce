<?php

namespace App\Services\Orders;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents PDF d'une commande : facture (bon de commande) et étiquette colis 10 × 15 cm avec QR code de suivi.
 */
class OrderDocuments
{
    public function invoice(Order $order): Response
    {
        $order->loadMissing(['items', 'store', 'payments']);

        return Pdf::loadView('pdf.invoice', ['order' => $order])->setPaper('a4')
            ->download('facture-'.ltrim($order->reference(), '#').'.pdf');
    }

    public function label(Order $order): Response
    {
        $order->loadMissing(['items', 'store']);

        return Pdf::loadView('pdf.label', ['order' => $order, 'qr' => $this->qr($order->trackingUrl())])
            ->setPaper([0, 0, 283.46, 425.2]) // 10 × 15 cm en points
            ->download('etiquette-'.ltrim($order->reference(), '#').'.pdf');
    }

    /**
     * QR code en image PNG (data URI) pour dompdf.
     */
    public function qr(string $data): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'scale' => 5,
            'imageTransparent' => false,
        ])))->render($data);
    }
}
