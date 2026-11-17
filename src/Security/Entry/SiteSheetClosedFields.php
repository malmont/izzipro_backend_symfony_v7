<?php

namespace App\Security\Entry;

/**
 * LES CHAMPS DE LA FICHE DU SITE FERMÉS À L'OPÉRATEUR D'UNE AGENCE ENTRÉE (lot AG-3c, décision du chef de projet du 17/11/2026, principes du
 * propriétaire : « jamais la caisse », « une agence n'obtient jamais un chiffre »). UNE SEULE LISTE pour les deux chemins : `PUT /api/entreprise`
 * (`EntryModeGuardSubscriber`) et la fiche Entreprise d'EasyAdmin (`AdminFieldGuard`). Rien ne change pour le commerçant ni pour le super
 * administrateur.
 */
final class SiteSheetClosedFields
{
    /** L'adresse qui reçoit commandes et demandes, la devise, le fuseau, le découpage des périodes, les adresses d'alerte, la conservation des
     *  données, les comptes comptables, l'appartenance aux applications, le niveau des courriels */
    public const FIELDS = ['email', 'currency', 'posTimezone', 'purchasePeriodFrequency', 'formSubmissionsEmail', 'posAlertsEmail', 'posStripeMode',
        'dataRetentionYears', 'retentionWriteMode', 'accountingAccounts', 'appMembershipMode', 'notificationLevel'];
    /** Tout l'onglet Caisse : les propriétés `pos…` (`posApprovalRefund`, `posOfflineEnabled`…) */
    public const PREFIX = 'pos';

    public static function closed(string $property): bool
    {
        if (in_array($property, self::FIELDS, true)) {
            return true;
        }

        return str_starts_with($property, self::PREFIX) && strlen($property) > strlen(self::PREFIX) && ctype_upper($property[strlen(self::PREFIX)]);
    }
}
