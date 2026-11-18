<?php

namespace App\Services\Group;

use App\Entity\User;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Group\Message\SendGroupInvitationMessage;
use App\Services\TenantConnectionManager;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * LE MULTI-BOUTIQUES (lot G-1a, 18/11/2026, plan validé : `docs/plan-entree-et-groupe.md` § 4, `docs/ecrans-et-contextes.md` § 12).
 * - « Passer en multi-boutiques » : un administrateur d'un site au module actif ; la fiche du groupe naît dans la base maître, ce site en est
 *   le SIÈGE.
 * - Associer une boutique existante : une INVITATION, que l'administrateur de cette boutique accepte lui-même, texte de consentement compris.
 *   La demande d'invitation rend la MÊME réponse que le site existe ou non, qu'il ait le module ou non, qu'il soit déjà dans un groupe : le
 *   traitement se fait en file (`SendGroupInvitationMessage`). Limiteur : 10 demandes par jour et par groupe.
 * - Quitter ne supprime rien ; le siège dissout le groupe quand il n'a plus d'autre boutique.
 */
class GroupService
{
    public const INVITATION_DAYS = 14;
    public const INVITATIONS_PER_DAY = 10;
    public const DEFAULT_MAX_SHOPS = 10;
    public const CONSENT_VERSION = 'g1-v1';

    public function __construct(
        private readonly GroupRepository $groups,
        private readonly ModuleAccessService $modules,
        private readonly TenantConnectionManager $tenants,
        private readonly MessageBusInterface $bus,
        private readonly ?\App\Services\Entry\EntryService $entries = null,
    ) {
    }

    /** Le texte de consentement que l'écran « accepter » et le courriel disent en clair (contrat du chef) */
    public static function consent(string $groupName, string $language = 'fr'): string
    {
        return $language === 'en'
            ? sprintf('The director of the group %s will be able to enter your shop and act in it; every action will be logged; you can leave the group at any time.', $groupName)
            : sprintf('Le directeur du groupe %s pourra entrer dans votre boutique et y agir ; chaque geste sera tracé ; vous pouvez quitter le groupe à tout moment.', $groupName);
    }

    /** @return array<string, mixed> le groupe du site courant et ses boutiques ; `group` null s'il n'en a pas */
    public function mine(string $site): array
    {
        $group = $this->groups->ofSite($site);
        if ($group === null) {
            return ['group' => null, 'shops' => [], 'canCreate' => $this->modules->isEnabled(ModuleAccessService::MULTI_SHOP)];
        }

        return ['group' => $this->view($group, $site), 'shops' => array_map(static fn (array $s): array => ['code' => (string) $s['tenant_code'], 'name' => (string) ($s['name'] ?? $s['tenant_code']),
            'origin' => (string) $s['origin'], 'joinedAt' => self::iso($s['joined_at'])], $this->groups->shops((int) $group['id'])), 'canCreate' => false];
    }

    /** @return array<string, mixed> @throws GroupException */
    public function create(string $site, User $by): array
    {
        if (!$this->modules->isEnabled(ModuleAccessService::MULTI_SHOP)) {
            throw new GroupException('MODULE_INACTIVE', 403, 'Le module Multi-boutiques n\'est pas actif sur ce site.', ['module' => 'module.multi_shop']);
        }
        if ($this->groups->ofSite($site) !== null) {
            throw new GroupException('GROUP_ALREADY_MEMBER', 409, 'Ce site appartient déjà à un groupe.');
        }
        $name = (string) ($this->tenantName($site) ?? $site);
        try {
            $id = $this->groups->create(mb_substr('groupe-' . $site, 0, 42) . '-' . bin2hex(random_bytes(3)), $name, $site, self::DEFAULT_MAX_SHOPS, $by->getUserIdentifier());
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new GroupException('GROUP_ALREADY_MEMBER', 409, 'Ce site appartient déjà à un groupe.');
            }

            throw $e;
        }
        $this->groups->event($id, 'created', $site, [], self::who($by));

        return $this->mine($site);
    }

    /**
     * Demander l'invitation d'une boutique existante : TOUJOURS la même réponse (le traitement, file comprise, ne dit rien de l'existence du
     * site visé). Seul le limiteur répond autrement (429), et il compte toutes les demandes.
     *
     * @return array{asked: true, expiresInDays: int}
     * @throws GroupException
     */
    public function invite(string $site, mixed $target, User $by, string $locale = 'fr'): array
    {
        $group = $this->headGroup($site);
        if (!$this->modules->isEnabled(ModuleAccessService::MULTI_SHOP)) {
            throw new GroupException('MODULE_INACTIVE', 403, 'Le module Multi-boutiques n\'est pas actif sur ce site.', ['module' => 'module.multi_shop']);
        }
        $code = is_string($target) ? mb_strtolower(trim($target)) : '';
        if (!$this->groups->askInvitation((int) $group['id'], mb_substr($code, 0, 50), self::INVITATIONS_PER_DAY, self::who($by))) {
            throw new GroupException('GROUP_INVITE_LIMIT', 429, 'Trop d\'invitations demandées aujourd\'hui pour ce groupe ; réessayez demain.', ['perDay' => self::INVITATIONS_PER_DAY]);
        }
        $this->bus->dispatch(new SendGroupInvitationMessage((int) $group['id'], mb_substr($code, 0, 50), self::who($by), $locale === 'en' ? 'en' : 'fr'));

        return ['asked' => true, 'expiresInDays' => self::INVITATION_DAYS];
    }

    /** @return list<array<string, mixed>> les invitations en attente adressées au site courant, avec le texte de consentement */
    public function invitationsFor(string $site, string $language = 'fr'): array
    {
        return array_map(fn (array $i): array => ['id' => (int) $i['id'], 'group' => (string) $i['group_name'], 'head' => (string) ($this->tenantName((string) $i['head_tenant_code']) ?? $i['head_tenant_code']),
            'expiresAt' => self::iso($i['expires_at']), 'consent' => self::consent((string) $i['group_name'], $language), 'consentVersion' => self::CONSENT_VERSION], $this->groups->openInvitationsFor($site));
    }

    /**
     * Accepter : seulement avec le texte de consentement (`consent` = sa version) ; la boutique entre dans le groupe (origine `associated`).
     *
     * @return array<string, mixed>
     * @throws GroupException
     */
    public function accept(string $site, int $id, mixed $consent, User $by): array
    {
        $invitation = $this->openInvitation($site, $id);
        if ($consent !== self::CONSENT_VERSION) {
            throw new GroupException('GROUP_CONSENT_REQUIRED', 422, 'Pour rejoindre le groupe, acceptez le texte qui vous est présenté.', ['consentVersion' => self::CONSENT_VERSION]);
        }
        $outcome = $this->groups->addShop((int) $invitation['group_id'], $site, 'associated', $by->getUserIdentifier());
        if ($outcome === 'gone') {
            throw new GroupException('GROUP_INVITATION_NOT_FOUND', 404, 'Invitation inconnue, échue ou déjà traitée.');
        }
        if ($outcome === 'taken') {
            throw new GroupException('GROUP_ALREADY_MEMBER', 409, 'Ce site appartient déjà à un groupe : quittez-le d\'abord.');
        }
        if ($outcome === 'limit') {
            throw new GroupException('GROUP_SHOP_LIMIT', 409, 'Le groupe a atteint son nombre de boutiques.');
        }
        $this->groups->answer($id, true, $by->getUserIdentifier());
        $this->groups->event((int) $invitation['group_id'], 'joined', $site, ['invitation' => $id, 'consentVersion' => self::CONSENT_VERSION], self::who($by));

        return $this->mine($site);
    }

    /** @throws GroupException */
    public function refuse(string $site, int $id, User $by): void
    {
        $invitation = $this->openInvitation($site, $id);
        $this->groups->answer($id, false, $by->getUserIdentifier());
        $this->groups->event((int) $invitation['group_id'], 'refused', $site, ['invitation' => $id], self::who($by));
    }

    /**
     * Quitter : une boutique quitte son groupe (son administrateur), ou le siège retire une boutique. Le siège ne quitte pas un groupe qui a encore
     * d'autres boutiques : il le dissout quand il est seul. Rien n'est supprimé.
     *
     * @return array<string, mixed>
     * @throws GroupException
     */
    public function leave(string $site, ?string $shop, User $by): array
    {
        $group = $this->groups->ofSite($site);
        if ($group === null) {
            throw new GroupException('GROUP_NOT_MEMBER', 404, 'Ce site n\'appartient à aucun groupe.');
        }
        $isHead = $group['head_tenant_code'] === $site;
        $target = $shop !== null && $shop !== '' ? $shop : $site;
        if ($target !== $site && !$isHead) {
            throw new GroupException('GROUP_HEAD_ONLY', 403, 'Seul le siège retire une autre boutique du groupe.');
        }
        if ($target === $group['head_tenant_code']) {
            if (!$this->groups->dissolve((int) $group['id'], (string) $group['head_tenant_code'], $by->getUserIdentifier())) {
                throw new GroupException('GROUP_NOT_EMPTY', 409, 'Le siège dissout le groupe quand il n\'a plus d\'autre boutique.');
            }
            $this->entries?->endForGroup((int) $group['id'], null, 'dissolved'); // lot G-1b : plus aucune entrée du directeur
            $this->groups->event((int) $group['id'], 'dissolved', $site, [], self::who($by));

            return $this->mine($site);
        }
        if (!$this->groups->leave((int) $group['id'], $target, $by->getUserIdentifier())) {
            throw new GroupException('GROUP_NOT_MEMBER', 404, 'Cette boutique n\'est pas dans le groupe.');
        }
        $this->entries?->endForGroup((int) $group['id'], $target, 'left'); // lot G-1b : les entrées du directeur dans cette boutique se ferment tout de suite
        $this->groups->event((int) $group['id'], 'left', $target, ['by' => $isHead && $target !== $site ? 'head' : 'shop'], self::who($by));

        return $this->mine($site);
    }

    /** @return array<string, mixed> le groupe dont ce site est le SIÈGE @throws GroupException */
    private function headGroup(string $site): array
    {
        $group = $this->groups->ofSite($site);
        if ($group === null || $group['head_tenant_code'] !== $site) {
            throw new GroupException('GROUP_HEAD_ONLY', 403, 'Seul le siège d\'un groupe invite une boutique.');
        }

        return $group;
    }

    /** @return array<string, mixed> @throws GroupException */
    private function openInvitation(string $site, int $id): array
    {
        $invitation = $this->groups->invitation($id);
        if ($invitation === null || $invitation['tenant_code'] !== $site || $invitation['accepted_at'] !== null || $invitation['refused_at'] !== null
            || strtotime((string) $invitation['expires_at'] . ' UTC') <= time()) {
            throw new GroupException('GROUP_INVITATION_NOT_FOUND', 404, 'Invitation inconnue, échue ou déjà traitée.');
        }

        return $invitation;
    }

    /** @param array<string, mixed> $group @return array<string, mixed> */
    private function view(array $group, string $site): array
    {
        return ['id' => (int) $group['id'], 'code' => (string) $group['code'], 'name' => (string) $group['name'], 'head' => (string) $group['head_tenant_code'],
            'role' => $group['head_tenant_code'] === $site ? 'head' : 'shop', 'maxShops' => (int) $group['max_shops']];
    }

    private function tenantName(string $code): ?string
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT name FROM tenants WHERE code = ?');
        $stmt->execute([$code]);
        $name = $stmt->fetchColumn();

        return is_string($name) ? $name : null;
    }

    private static function who(User $user): string
    {
        return mb_substr(trim((string) $user->getFirstname()) ?: 'administrateur', 0, 100);
    }

    private static function iso(mixed $at): ?string
    {
        return $at === null ? null : (new \DateTimeImmutable((string) $at, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
