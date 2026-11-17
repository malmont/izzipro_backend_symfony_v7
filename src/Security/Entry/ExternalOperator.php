<?php

namespace App\Security\Entry;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * L'OPÉRATEUR EXTÉRIEUR (lot AG-3b, 16/11/2026, `docs/ecrans-et-contextes.md` § 7) : un membre d'agence entré dans le site d'un
 * client par un jeton d'entrée. Ce n'est PAS un compte du site : rien n'est créé dans sa base, aucun mot de passe n'y existe, et
 * l'objet n'est jamais enregistré. Il porte les rôles d'un administrateur pour passer les règles d'accès des écrans de construction ;
 * la garde du mode (`EntryModeGuardSubscriber`) ne lui ouvre QUE ces écrans, fermée par défaut.
 *
 * Son identifiant est ce que le commerçant lit au journal de son site et dans « Accès extérieurs » : « Agence X, prénom », rien de
 * plus (ni adresse, ni nom de famille). Il ne porte jamais d'arobase : il ne se confond avec l'adresse d'aucun compte du site.
 */
final class ExternalOperator implements UserInterface
{
    /** Le rôle qui marque l'opérateur : il n'est donné à aucun compte et ne s'attribue nulle part */
    public const ROLE = 'ROLE_ENTRY_AGENCY';

    public function __construct(
        private readonly int $entryId,
        private readonly int $agencyId,
        private readonly string $agencyName,
        private readonly string $firstName,
        private readonly string $home,
        /** `created` : l'agence a créé ce site ; `attached` : un site existant rattaché à l'agence */
        private readonly string $origin = 'attached',
    ) {
    }

    /** « Agence X, prénom », borné (l'auteur d'une ligne de journal tient en 180 caractères), sans arobase ni caractère de contrôle */
    public static function label(string $agencyName, string $firstName): string
    {
        $clean = static fn (string $text, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F@]/u', ' ', $text))), 0, $max);
        $agency = $clean($agencyName, 80);
        $first = $clean($firstName, 40);

        // un nom qui commence déjà par « Agence » ne la répète pas (« Agence d'essai », pas « Agence Agence d'essai ») ; correction du 17/11/2026
        $named = $agency === '' ? 'Agence ?' : (preg_match('/^agence\b/iu', $agency) === 1 ? $agency : 'Agence ' . $agency);

        return $named . ($first !== '' ? ', ' . $first : '');
    }

    /** L'entrée du compte de la plateforme, telle que le commerçant la lit : « Plateforme », suivi du prénom ; jamais une adresse */
    public static function platformLabel(string $firstName): string
    {
        $first = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F@]/u', ' ', $firstName))), 0, 40);

        return 'Plateforme' . ($first !== '' ? ', ' . $first : '');
    }

    public function getUserIdentifier(): string
    {
        return self::label($this->agencyName, $this->firstName);
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

    public function getAgencyId(): int
    {
        return $this->agencyId;
    }

    public function getAgencyName(): string
    {
        return $this->agencyName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getHome(): string
    {
        return $this->home;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }
}
