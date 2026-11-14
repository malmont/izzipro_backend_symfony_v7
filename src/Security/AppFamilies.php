<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Familles d'applications à membres (APPS-MEMBRES, `docs/apps-membres.md`). Décision du propriétaire (14/11/2026) : TROIS familles,
 * `boutique` (le site et son application mobile), `livingmemory` (l'application Living Memory) et `pos` (le client de la caisse).
 * Le web et le mobile d'une même famille partagent l'appartenance et ses conditions ; « Rejoindre » est obligatoire ENTRE familles.
 * Une appartenance n'est pas un rôle : les rôles disent ce qu'un compte a le droit de faire, l'appartenance dit dans quelle famille
 * le client est entré de lui-même.
 *
 * Ne sont pas des familles à membres : Mémoires Vivantes web (l'outil de l'équipe : administrateurs, responsable, biographes ; code
 * d'application `memoires`, `TeamRoles::MEMOIRES_WEB_APP`), la gestion et EasyAdmin (équipe seulement), la Boussole (comptes
 * séparés, `esg_user`). Un compte d'équipe n'est jamais membre et ne passe jamais par « Rejoindre » : son accès vient de son rôle.
 */
final class AppFamilies
{
    public const BOUTIQUE = 'boutique';
    public const LIVINGMEMORY = 'livingmemory';
    public const POS = 'pos';

    /** Liste fermée des familles : la valeur de `user_app_membership.app` et du champ `app` du jeton */
    public const ALL = [self::BOUTIQUE, self::LIVINGMEMORY, self::POS];

    /** Ce qu'un front envoie dans `app` (code de son application, ou la famille elle-même) => famille */
    public const APPS = [
        'web' => self::BOUTIQUE, 'mobile' => self::BOUTIQUE, 'boutique' => self::BOUTIQUE,
        'livingmemory' => self::LIVINGMEMORY,
        'pos' => self::POS,
    ];

    /**
     * D'où viennent les textes des conditions d'une famille (aucun nouveau texte dans ce lot) : le profil d'application nommé,
     * dont un champ vide prend celui de la fiche Entreprise ; null : la fiche Entreprise du site, directement.
     */
    public const TERMS_PROFILE = [self::BOUTIQUE => null, self::LIVINGMEMORY => 'livingmemory', self::POS => 'pos'];

    public const SOURCE_REGISTER = 'register';
    public const SOURCE_JOIN = 'join';
    public const SOURCE_GUEST_CHECKOUT = 'guest_checkout';
    public const SOURCE_POS_LINK = 'pos_link';
    public const SOURCE_BACKFILL = 'backfill';
    public const SOURCES = [self::SOURCE_REGISTER, self::SOURCE_JOIN, self::SOURCE_GUEST_CHECKOUT, self::SOURCE_POS_LINK, self::SOURCE_BACKFILL];

    /** Ressource du journal content_audit_log */
    public const AUDIT_RESOURCE = 'user_app';

    /** Mode du site (lot M3) : rien n'est refusé, ou une connexion à une famille dont le compte n'est pas membre passe par « Rejoindre » */
    public const MODE_OBSERVE = 'observe';
    public const MODE_REQUIRE = 'require';
    public const MODES = [self::MODE_OBSERVE, self::MODE_REQUIRE];

    /** Raison du refus `APP_MEMBERSHIP_REQUIRED` : le compte n'est pas membre de la famille (la seule raison ; le champ reste pour la suite) */
    public const REASON_MEMBERSHIP = 'membership';

    /** Rôles qui font d'un compte un compte d'équipe, en plus des rôles d'équipe de `TeamRoles` */
    public const STAFF_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_USER_POS', 'ROLE_POS_MANAGER', 'ROLE_SUPPORT'];

    /** Famille de ce que le front a envoyé, ou null (rien d'envoyé, valeur inconnue, valeur qui n'est pas un texte) */
    public static function of(mixed $app): ?string
    {
        return is_string($app) ? (self::APPS[strtolower(trim($app))] ?? null) : null;
    }

    /** @param list<string>|UserInterface $roles */
    public static function isTeam(array|UserInterface $roles): bool
    {
        $roles = $roles instanceof UserInterface ? $roles->getRoles() : $roles;

        return array_intersect([...self::STAFF_ROLES, ...array_values(TeamRoles::ALL)], $roles) !== [];
    }
}
