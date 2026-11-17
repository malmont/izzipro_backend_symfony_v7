<?php

namespace App\Security\Entry;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * L'ENTRÉE PORTÉE PAR UNE SESSION D'EASYADMIN (lot AG-3c, 17/11/2026). EasyAdmin sert tous les sites depuis le même domaine, le site
 * actif étant gardé dans la session (`?tenant=`) : entrer dans le site d'un client REMPLACE, dans cette session, la connexion du site
 * d'attache. La session garde alors l'entrée : numéro, mode, site d'attache, site visé, compte de la plateforme (mode super
 * administrateur), échéance. Elle est relue à chaque requête (`AdminEntrySubscriber`) ; refusée, la session est vidée.
 */
final class AdminEntrySession
{
    public const KEY = '_admin_entry';

    /** @param array{id: int, mode: string, home: string, target: string, account: ?int, email: ?string, expiresAt: string} $entry */
    public static function put(SessionInterface $session, array $entry): void
    {
        $session->set(self::KEY, $entry);
    }

    /** @return array{id: int, mode: string, home: string, target: string, account: ?int, email: ?string, expiresAt: string}|null */
    public static function get(SessionInterface $session): ?array
    {
        $entry = $session->get(self::KEY);

        return is_array($entry) && is_int($entry['id'] ?? null) && is_string($entry['mode'] ?? null) && is_string($entry['home'] ?? null) && is_string($entry['target'] ?? null) ? $entry : null;
    }
}
