<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * La validation du code à usage unique, gardée dans la session de l'espace d'administration (EasyAdmin). Lot SÉCURITÉ-2
 * (16/11/2026) : elle est LIÉE AU COMPTE qui a saisi le code. Avant, un simple drapeau « vrai » : une reconnexion dans la même session
 * avec un AUTRE compte (son mot de passe connu) sautait le code de ce compte-là.
 * La marque est l'identifiant du compte (son adresse, en minuscules) : le compte de la plateforme, qui porte la même adresse sur
 * chaque site, garde sa validation en passant d'un site à l'autre ; tout autre compte doit saisir son propre code.
 */
final class OtpSession
{
    public const KEY = 'otp_validated';

    public static function validated(SessionInterface $session, User $user): bool
    {
        $mark = $session->get(self::KEY);

        return is_string($mark) && $mark !== '' && hash_equals($mark, self::mark($user));
    }

    public static function markValidated(SessionInterface $session, User $user): void
    {
        $session->set(self::KEY, self::mark($user));
    }

    private static function mark(User $user): string
    {
        return mb_strtolower(trim($user->getUserIdentifier()));
    }
}
