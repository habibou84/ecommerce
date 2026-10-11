<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Le prestataire refuse ou ne répond pas : le paiement bascule vers le prestataire suivant s'il y en a un.
 */
class GatewayException extends RuntimeException {}
