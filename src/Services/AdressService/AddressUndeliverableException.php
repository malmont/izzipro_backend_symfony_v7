<?php

namespace App\Services\AdressService;

/**
 * Adresse refusée par la vérification de livraison (lot ERREURS-1, 12/11/2026) : refus métier, dont le texte (« Adresse invalide : … »,
 * avec les motifs du vérificateur) est destiné au client. Avant, une `RuntimeException` générique : rien ne la distinguait d'une panne du
 * service d'adresses.
 */
final class AddressUndeliverableException extends \RuntimeException
{
}
