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
    /**
     * Lot RETOUR (18/11/2026) : ce qu'il faut pour REVENIR au site d'attache sans nouvelle connexion, gardé côté serveur dans la session
     * (jamais dans un paramètre ni un cookie lisible) : le compte d'attache (numéro, adresse), l'heure d'origine de son code, l'empreinte de
     * son mot de passe à l'ouverture de l'entrée, le numéro de l'entrée.
     */
    public const RETURN_KEY = '_admin_entry_return';

    /** @param array{home: string, account: int, email: string, otpAt: int, password: string, entry: int} $back */
    public static function keepReturn(SessionInterface $session, array $back): void
    {
        $session->set(self::RETURN_KEY, $back);
    }

    /** Lit ET retire le retour (usage unique) @return array{home: string, account: int, email: string, otpAt: int, password: string, entry: int}|null */
    public static function takeReturn(SessionInterface $session): ?array
    {
        $back = $session->get(self::RETURN_KEY);
        $session->remove(self::RETURN_KEY);

        return is_array($back) && is_string($back['home'] ?? null) && is_int($back['account'] ?? null) && is_string($back['email'] ?? null) && is_int($back['otpAt'] ?? null)
            && is_string($back['password'] ?? null) && is_int($back['entry'] ?? null) ? $back : null;
    }

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
