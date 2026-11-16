<?php

namespace App\Security;

use App\Entity\User;
use App\Services\Platform\PlatformAccessJournal;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * La validation du code à usage unique, gardée dans la session de l'espace d'administration (EasyAdmin). Lot SÉCURITÉ-2
 * (16/11/2026) : elle est LIÉE AU COMPTE qui a saisi le code, ET AU SITE. Avant, un simple drapeau « vrai » : une reconnexion dans la
 * même session avec un autre compte sautait le code ; et une session garde ses attributs d'un site à l'autre (`/login?tenant=…`) :
 * une marque faite de la seule adresse aurait laissé passer le compte de même adresse sur un AUTRE site (relecture du lot).
 * - Un compte ordinaire : `site|<code du site>|<identifiant>|<adresse>` : valable sur ce site, pour ce compte seulement.
 * - Le compte de la plateforme (même adresse sur chaque site) : `platform|<adresse>`, accepté seulement pour un compte de la
 *   plateforme : il garde sa validation en passant d'un site à l'autre ; un compte ordinaire ne peut jamais s'en prévaloir.
 */
final class OtpSession
{
    public const KEY = 'otp_validated';

    public static function validated(SessionInterface $session, User $user, ?string $site): bool
    {
        $mark = $session->get(self::KEY);
        $expected = self::mark($user, $site);

        return is_string($mark) && $mark !== '' && $expected !== null && hash_equals($expected, $mark);
    }

    public static function markValidated(SessionInterface $session, User $user, ?string $site): void
    {
        $mark = self::mark($user, $site);
        $mark === null ? $session->remove(self::KEY) : $session->set(self::KEY, $mark);
    }

    private static function mark(User $user, ?string $site): ?string
    {
        $email = mb_strtolower(trim($user->getUserIdentifier()));
        if (PlatformAccessJournal::isPlatformAccount($user->getRoles())) {
            return 'platform|' . $email;
        }
        if ($site === null || $site === '' || $user->getId() === null) {
            return null; // hors d'un site connu : jamais validé
        }

        return 'site|' . $site . '|' . $user->getId() . '|' . $email;
    }
}
