<?php

namespace App\Services;

/**
 * Piège à robots des formulaires publics d'une seule page : contact (POST /api/contacts, /api/contacts/create,
 * /api/contact/submit) et prise de rendez-vous (POST /api/reservations), 11/11/2026 (spams reçus par esgboost).
 *
 * Deux champs envoyés par le navigateur, comme pour les formulaires à étapes (FormSubmissionInputDto) :
 * - site : champ caché, invisible pour un humain, qu'un robot remplit ;
 * - fillSeconds : secondes entre l'affichage du formulaire et l'envoi.
 * Un robot pris au piège reçoit la réponse d'un envoi réussi : rien n'est enregistré ni envoyé.
 *
 * Mode tolérant : un corps sans fillSeconds passe comme avant (fronts pas encore à jour, applications mobiles qui
 * réservent). Seul un corps qui annonce fillSeconds est contrôlé. Durée minimale plus courte que celle des formulaires
 * à étapes : une seule page, souvent préremplie par le navigateur ; un vrai message perdu coûte plus qu'un spam.
 */
final class PublicFormTrap
{
    public const MIN_FILL_SECONDS = 4;

    /** @param array<string, mixed>|object|null $body corps JSON décodé */
    public static function caught(mixed $body, string $route): bool
    {
        $body = is_object($body) ? get_object_vars($body) : $body;
        if (!is_array($body) || !array_key_exists('fillSeconds', $body)) {
            return false;
        }
        $fill = $body['fillSeconds'];
        $site = $body['site'] ?? null;
        $caught = (is_string($site) && trim($site) !== '') || !is_numeric($fill) || (float) $fill < self::MIN_FILL_SECONDS;
        if ($caught) {
            // aucune donnée du visiteur dans le journal : la route seulement
            error_log(sprintf('[anti-robot] envoi écarté sur %s', $route));
        }

        return $caught;
    }
}
