<?php

namespace App\Security\Entry;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * LE DIRECTEUR D'UN GROUPE entré dans une boutique du groupe (lot G-1b, 18/11/2026, `docs/ecrans-et-contextes.md` § 12). Comme
 * l'opérateur d'une agence, ce n'est PAS un compte de la boutique : rien n'est créé dans sa base, l'objet n'est jamais enregistré. Il porte
 * les rôles d'un administrateur ; la garde du mode (`EntryModeGuardSubscriber`) lui ouvre tout le site SAUF le paiement (Stripe), les
 * remboursements et le compte (`@compte`) : décision du propriétaire du 18/11/2026.
 *
 * Son identifiant est ce que le commerçant lit au journal de sa boutique et dans « Accès extérieurs » : « Groupe X, prénom », sans adresse.
 */
final class GroupOperator implements UserInterface, EquatableInterface
{
    /** Le rôle qui marque le directeur entré : il n'est donné à aucun compte et ne s'attribue nulle part */
    public const ROLE = 'ROLE_ENTRY_GROUP';

    public function __construct(
        private readonly int $entryId,
        private readonly int $groupId,
        private readonly string $groupName,
        private readonly string $firstName,
        private readonly string $home,
    ) {
    }

    /** « Groupe X, prénom », borné, sans arobase ni caractère de contrôle ; un nom qui commence par « Groupe » ne le répète pas */
    public static function label(string $groupName, string $firstName): string
    {
        $clean = static fn (string $text, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F@]/u', ' ', $text))), 0, $max);
        $group = $clean($groupName, 80);
        $first = $clean($firstName, 40);
        $named = $group === '' ? 'Groupe ?' : (preg_match('/^groupe\b/iu', $group) === 1 ? $group : 'Groupe ' . $group);

        return $named . ($first !== '' ? ', ' . $first : '');
    }

    public function getUserIdentifier(): string
    {
        return self::label($this->groupName, $this->firstName);
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_ADMIN', self::ROLE, 'ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getEntryId(): int
    {
        return $this->entryId;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function getGroupName(): string
    {
        return $this->groupName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getHome(): string
    {
        return $this->home;
    }

    /** Dans une session d'EasyAdmin, le directeur relu à chaque requête est le même s'il porte la même entrée, le même groupe, le même siège */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && $user->entryId === $this->entryId && $user->groupId === $this->groupId && $user->home === $this->home;
    }
}
