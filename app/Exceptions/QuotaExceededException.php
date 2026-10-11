<?php

namespace App\Exceptions;

/**
 * Limite de l'offre atteinte (le message s'adresse au marchand, pas à ses clients).
 */
class QuotaExceededException extends BusinessRuleException {}
