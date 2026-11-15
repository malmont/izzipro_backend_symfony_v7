<?php

namespace App\Import;

/** Refus d'un import, avec un code stable que les applications traduisent (lot IMP-1) */
final class ImportException extends \RuntimeException
{
    public const MESSAGES = [
        'IMPORT_SOURCE_UNKNOWN' => 'Source d\'import inconnue.',
        'IMPORT_FILE_REQUIRED' => 'Fichier d\'import attendu.',
        'IMPORT_FILE_TOO_BIG' => 'Fichier trop lourd.',
        'IMPORT_ALREADY_ACTIVE' => 'Un import est déjà en cours sur ce site.',
        'IMPORT_NOT_A_PRODUCT_FILE' => 'Ce fichier n\'est pas un export de produits : les colonnes « Handle » et « Title » sont attendues.',
        'IMPORT_TOO_MANY_ROWS' => 'Fichier trop long.',
        'IMPORT_EMPTY_FILE' => 'Fichier vide ou illisible.',
        'IMPORT_NOT_UTF8' => 'Ce fichier n\'est pas encodé en UTF-8 : exportez-le de nouveau, ou enregistrez-le en UTF-8.',
        'IMPORT_NOT_AVAILABLE' => 'L\'import n\'est pas disponible sur ce site.',
        'IMPORT_QUEUE_UNAVAILABLE' => 'L\'import n\'a pas pu être lancé : réessayez dans un instant.',
        'IMPORT_NOT_FOUND' => 'Import introuvable.',
        'IMPORT_NOT_READY' => 'Cet import n\'attend pas de confirmation.',
        'IMPORT_NOTHING_TO_IMPORT' => 'Aucun article à importer dans ce fichier.',
        'IMPORT_TAX_RATE_UNKNOWN' => 'Les prix du fichier ne sont pas dans le mode du site (taxes comprises ou hors taxes) et le taux des taxes du magasin est inconnu : réglez l\'adresse et les taxes du site.',
        'IMPORT_NOT_CANCELLABLE' => 'Cet import ne peut pas être annulé dans son état.',
        'IMPORT_CANCEL_WINDOW_PASSED' => 'Le délai d\'annulation de cet import est passé.',
        'IMPORT_CANCEL_INTERRUPTED' => 'L\'annulation a été interrompue : relancez-la.',
        'VALIDATION_FAILED' => 'Choix de confirmation invalides.',
    ];

    /** @param array<string, mixed> $extra */
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct(self::MESSAGES[$errorCode] ?? $errorCode);
    }
}
