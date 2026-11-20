<?php

namespace App\MemoiresVivantes\Services;

/**
 * Lot WEBHOOK-SIG : la session d'un livre n'a pas pu être relue chez Stripe (panne passagère). Rien n'est écrit ; le webhook répond 503
 * pour que Stripe renvoie l'événement plus tard.
 */
final class BookPaymentRetryLater extends \RuntimeException
{
}
