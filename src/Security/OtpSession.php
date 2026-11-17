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
    /** Lot AG-3c : l'heure (UTC, secondes) de la validation du code, gardée avec la marque ; l'entrée dans un autre site depuis
     *  EasyAdmin exige un code validé depuis moins de 12 heures */
    public const AT_KEY = 'otp_validated_at';

    public static function validated(SessionInterface $session, User $user, ?string $site): bool
    {
        $mark = $session->get(self::KEY);
        $expected = self::mark($user, $site);

        return is_string($mark) && $mark !== '' && $expected !== null && hash_equals($expected, $mark);
    }

    public static function markValidated(SessionInterface $session, User $user, ?string $site): void
    {
        $mark = self::mark($user, $site);
        if ($mark === null) {
            $session->remove(self::KEY);
            $session->remove(self::AT_KEY);

            return;
        }
        $session->set(self::KEY, $mark);
        $session->set(self::AT_KEY, time());
    }

    /**
     * Lot AG-3c : la validation vaut-elle pour CE compte sur CE site, et depuis combien de secondes ? null : pas validé (ou validé sans
     * heure connue, avant ce lot : on redemande le code).
     */
    public static function validatedSince(SessionInterface $session, User $user, ?string $site): ?int
    {
        $at = $session->get(self::AT_KEY);
        if (!self::validated($session, $user, $site) || !is_int($at) || $at > time()) {
            return null;
        }

        return time() - $at;
    }

    /** Lot AG-3c : marque posée par un laissez-passer d'entrée (compte de la plateforme), à l'heure de la validation d'origine */
    public static function markValidatedAt(SessionInterface $session, User $user, ?string $site, int $at): void
    {
        self::markValidated($session, $user, $site);
        if ($session->has(self::KEY)) {
            $session->set(self::AT_KEY, min($at, time()));
        }
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
