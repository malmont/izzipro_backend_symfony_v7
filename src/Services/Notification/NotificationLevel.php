<?php

namespace App\Services\Notification;

/**
 * Niveaux des courriels de suivi (lot NOTIF, contrat validé par le propriétaire le 15/11/2026, `docs/notifications-courriels.md`).
 * Un courriel « indispensable » part toujours et n'est jamais compté ; un « urgent » part tout de suite à partir d'« Essentiel » ; un
 * courriel de « suivi » est le seul que les niveaux retiennent ou regroupent.
 */
final class NotificationLevel
{
    public const NONE = 'none';
    public const ESSENTIAL = 'essential';
    public const NORMAL = 'normal';
    public const ALL = 'all';
    /** Dans l'ordre où ils sont proposés */
    public const LEVELS = [self::NONE, self::ESSENTIAL, self::NORMAL, self::ALL];

    /** Au plus ce nombre de courriels de notification (urgent + suivi) par jour du magasin et par personne, quel que soit le niveau */
    public const DAILY_CAP = 3;
    /** Aucun courriel de suivi avant cette heure du magasin (la règle du résumé des pertes, devenue commune) */
    public const FROM_HOUR = 8;

    public static function isValid(mixed $level): bool
    {
        return is_string($level) && in_array($level, self::LEVELS, true);
    }
}
