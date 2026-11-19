<?php

namespace App\Services\Group;

use App\Entity\User;
use App\Services\Entry\EntryService;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;

/**
 * Lot G-1d (19/11/2026, contrat validé par le chef, décision du propriétaire ; `docs/ecrans-et-contextes.md` § 17 bis) : le lien « MÊME
 * ENTREPRISE » d'une boutique ASSOCIÉE. Une boutique est de la même entreprise que le siège si son origine est `head` ou `created`, ou si elle
 * est `associated` AVEC le lien confirmé (`same()`, la seule règle : G-4 pour le coût, G-5 pour le transfert).
 * - Poser : à l'acceptation de l'invitation (case facultative, `GroupService::accept`) ; ou à la DEMANDE du directeur, que l'administrateur de
 *   la boutique confirme lui-même (courriel sans lien d'action, 14 jours). Texte de consentement versionné, exigé à l'identique.
 * - Retirer : l'administrateur de la boutique seul, effet immédiat (le directeur est prévenu) ; ou le directeur (la boutique est prévenue).
 * - Traces : journal du groupe (base maître) et journal des DEUX sites (« Accès extérieurs »).
 */
class GroupCompanyService
{
    public const CONSENT_VERSION = 'g1d-v1';
    public const REQUEST_DAYS = 14;
    public const JOURNAL_ACTION = 'same_company';

    public function __construct(
        private readonly GroupRepository $groups,
        private readonly GroupCompanyRepository $links,
        private readonly ModuleAccessService $modules,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantDatabaseConnector $databases,
        private readonly GroupCompanyMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Le texte de consentement, dit en clair à l'acceptation et à la confirmation */
    public static function consent(string $groupName, string $language = 'fr'): string
    {
        return $language === 'en'
            ? sprintf('This shop belongs to the same company as the head of the group %s. The director will be able to transfer stock to it and copy the purchase cost into it. You can remove this link at any time, on your own and immediately.', $groupName)
            : sprintf('Cette boutique appartient à la même entreprise que le siège du groupe %s. Le directeur pourra y transférer du stock et y copier le coût d\'achat. Vous pouvez retirer ce lien à tout moment, seul et tout de suite.', $groupName);
    }

    /** LA règle : le siège et les boutiques créées le sont toujours ; une boutique associée seulement avec le lien confirmé ; hors groupe : non */
    public function same(int $groupId, string $site): bool
    {
        $link = $this->links->link($groupId, $site);

        return $link !== null && self::sameFromRow($link);
    }

    /** @param array<string, mixed> $row un lien ouvert (`origin`, `same_company`) */
    public static function sameFromRow(array $row): bool
    {
        return ($row['origin'] ?? null) !== 'associated' || filter_var($row['same_company'] ?? false, \FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<string, mixed> $shop une ligne de `GroupRepository::shops` @return array{state: string, since: ?string, requestExpiresAt: ?string} */
    public function state(int $groupId, array $shop): array
    {
        if (self::sameFromRow($shop)) {
            return ['state' => 'confirmed', 'since' => ($shop['origin'] ?? null) === 'associated' ? self::iso($shop['same_company_since'] ?? null) : null, 'requestExpiresAt' => null];
        }
        $pending = $this->links->pending($groupId, (string) $shop['tenant_code']);

        return ['state' => $pending !== null ? 'pending' : 'none', 'since' => null, 'requestExpiresAt' => $pending !== null ? self::iso($pending['expires_at']) : null];
    }

    /** À l'acceptation de l'invitation, la case cochée : le lien est déjà né avec la boutique (`GroupRepository::addShop`) ; trace et avis au siège */
    public function setAtAccept(int $groupId, string $site, User $by, string $language = 'fr'): void
    {
        $this->trace($groupId, 'same_company_set_at_accept', $site, 'shop', $by);
        $group = $this->groups->find($groupId);
        $this->mailer->send($this->dbname((string) ($group['head_tenant_code'] ?? '')), 'set_at_accept', ['%group%' => (string) ($group['name'] ?? ''), '%site%' => $this->name($site)], $language);
    }

    /**
     * Le directeur DEMANDE le lien pour une boutique associée.
     *
     * @return array{asked: true, expiresInDays: int}
     * @throws GroupException
     */
    public function request(string $head, string $shop, User $by, string $language = 'fr'): array
    {
        $group = $this->headGroup($head);
        $link = $this->links->link((int) $group['id'], $shop);
        if ($link === null || $shop === $head) {
            throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'Cette boutique n\'est pas dans votre groupe.');
        }
        if ($link['origin'] !== 'associated') {
            throw new GroupException('SAME_COMPANY_NOT_ASSOCIATED', 422, 'Cette boutique est déjà de la même entreprise (créée par le groupe).');
        }
        if (self::sameFromRow($link)) {
            throw new GroupException('SAME_COMPANY_ALREADY', 409, 'Le lien « même entreprise » est déjà posé pour cette boutique.');
        }
        $expires = new \DateTimeImmutable('+' . self::REQUEST_DAYS . ' days', new \DateTimeZone('UTC'));
        $id = $this->links->request((int) $group['id'], $shop, self::who($by), $expires);
        if ($id === null) {
            throw new GroupException('SAME_COMPANY_PENDING', 409, 'Une demande attend déjà la réponse de cette boutique.');
        }
        $this->trace((int) $group['id'], 'same_company_requested', $shop, 'head', $by, ['request' => $id]);
        $this->mailer->send($this->dbname($shop), 'requested', ['%group%' => (string) $group['name'], '%head%' => $this->name($head), '%site%' => $this->name($shop), '%days%' => (string) self::REQUEST_DAYS,
            '%consent%' => self::consent((string) $group['name'], $language)], $language);

        return ['asked' => true, 'expiresInDays' => self::REQUEST_DAYS];
    }

    /** @return array{sameCompany: bool, since: ?string, request: ?array<string, mixed>} l'état vu par la BOUTIQUE */
    public function forShop(string $site, string $language = 'fr'): array
    {
        $group = $this->groups->ofSite($site);
        if ($group === null || $group['head_tenant_code'] === $site) {
            return ['sameCompany' => $group !== null, 'since' => null, 'request' => null];
        }
        $link = $this->links->link((int) $group['id'], $site);
        $pending = $link !== null && !self::sameFromRow($link) ? $this->links->pending((int) $group['id'], $site) : null;

        return ['sameCompany' => $link !== null && self::sameFromRow($link), 'since' => $link !== null && $link['origin'] === 'associated' ? self::iso($link['same_company_since']) : null,
            'request' => $pending === null ? null : ['id' => (int) $pending['id'], 'group' => (string) $group['name'], 'head' => $this->name((string) $group['head_tenant_code']),
                'expiresAt' => self::iso($pending['expires_at']), 'consent' => self::consent((string) $group['name'], $language), 'consentVersion' => self::CONSENT_VERSION]];
    }

    /** La BOUTIQUE confirme (son administrateur, texte de consentement à l'identique) @return array<string, mixed> @throws GroupException */
    public function confirm(string $site, int $id, mixed $consent, User $by, string $language = 'fr'): array
    {
        [$group] = $this->openRequest($site, $id);
        if ($consent !== self::CONSENT_VERSION) {
            throw new GroupException('GROUP_CONSENT_REQUIRED', 422, 'Pour poser ce lien, acceptez le texte qui vous est présenté.', ['consentVersion' => self::CONSENT_VERSION]);
        }
        if (!$this->links->answer($id, 'confirmed', self::who($by), self::CONSENT_VERSION, (int) $group['id'], $site)) {
            throw new GroupException('SAME_COMPANY_REQUEST_NOT_FOUND', 404, 'Demande inconnue, échue ou déjà traitée.');
        }
        $this->trace((int) $group['id'], 'same_company_confirmed', $site, 'shop', $by, ['request' => $id]);
        $this->mailer->send($this->dbname((string) $group['head_tenant_code']), 'confirmed', ['%group%' => (string) $group['name'], '%site%' => $this->name($site)], $language);

        return $this->forShop($site, $language);
    }

    /** @throws GroupException */
    public function refuse(string $site, int $id, User $by, string $language = 'fr'): void
    {
        [$group] = $this->openRequest($site, $id);
        if (!$this->links->answer($id, 'refused', self::who($by), null, (int) $group['id'], $site)) {
            throw new GroupException('SAME_COMPANY_REQUEST_NOT_FOUND', 404, 'Demande inconnue, échue ou déjà traitée.');
        }
        $this->trace((int) $group['id'], 'same_company_refused', $site, 'shop', $by, ['request' => $id]);
        $this->mailer->send($this->dbname((string) $group['head_tenant_code']), 'refused', ['%group%' => (string) $group['name'], '%site%' => $this->name($site)], $language);
    }

    /** La BOUTIQUE retire le lien, seule, tout de suite ; le directeur est prévenu @return array<string, mixed> @throws GroupException */
    public function removeByShop(string $site, User $by, string $language = 'fr'): array
    {
        $group = $this->groups->ofSite($site);
        if ($group === null || $group['head_tenant_code'] === $site) {
            throw new GroupException('GROUP_NOT_MEMBER', 404, 'Ce site n\'est pas une boutique associée d\'un groupe.');
        }
        if (!$this->links->setSameCompany((int) $group['id'], $site, false, null)) {
            throw new GroupException('SAME_COMPANY_NOT_SET', 409, 'Le lien « même entreprise » n\'est pas posé pour cette boutique.');
        }
        $this->removed($group, $site, 'shop', $by, $language);

        return $this->forShop($site, $language);
    }

    /**
     * Le DIRECTEUR retire le lien (ou retire sa demande en attente) ; la boutique est prévenue.
     *
     * @return array{sameCompany: array<string, mixed>}
     * @throws GroupException
     */
    public function removeByHead(string $head, string $shop, User $by, string $language = 'fr'): array
    {
        $group = $this->headGroup($head);
        $link = $this->links->link((int) $group['id'], $shop);
        if ($link === null || $shop === $head) {
            throw new GroupException('GROUP_SHOP_UNKNOWN', 404, 'Cette boutique n\'est pas dans votre groupe.');
        }
        if ($this->links->setSameCompany((int) $group['id'], $shop, false, null)) {
            $this->removed($group, $shop, 'head', $by, $language);
        } elseif ($this->links->cancelPending((int) $group['id'], $shop, self::who($by))) {
            $this->trace((int) $group['id'], 'same_company_request_cancelled', $shop, 'head', $by);
        } else {
            throw new GroupException('SAME_COMPANY_NOT_SET', 409, 'Le lien « même entreprise » n\'est pas posé pour cette boutique.');
        }
        $row = $this->links->link((int) $group['id'], $shop) ?? $link;

        return ['sameCompany' => $this->state((int) $group['id'], ['tenant_code' => $shop] + $row)];
    }

    /** La boutique quitte le groupe (ou le siège la retire, ou dissout) : sa demande en attente est close, le lien neuf repartira de zéro */
    public function onLeft(int $groupId, string $site, ?string $by): void
    {
        if ($this->links->cancelPending($groupId, $site, $by)) {
            $this->groups->event($groupId, 'same_company_request_cancelled', $site, ['reason' => 'left'], $by);
        }
    }

    /** @param array<string, mixed> $group */
    private function removed(array $group, string $shop, string $side, User $by, string $language): void
    {
        // l'événement que lira le transfert (G-5) : nouveaux transferts refusés, transferts seulement demandés annulés
        $this->trace((int) $group['id'], 'same_company_removed', $shop, $side, $by);
        $params = ['%group%' => (string) $group['name'], '%site%' => $this->name($shop)];
        $this->mailer->send($this->dbname($side === 'shop' ? (string) $group['head_tenant_code'] : $shop), $side === 'shop' ? 'removed_by_shop' : 'removed_by_head', $params, $language);
    }

    /** @return array{0: array<string, mixed>} le groupe de la demande EN ATTENTE adressée à ce site @throws GroupException */
    private function openRequest(string $site, int $id): array
    {
        $group = $this->groups->ofSite($site);
        $request = $this->links->find($id);
        if ($group === null || $request === null || $request['tenant_code'] !== $site || (int) $request['group_id'] !== (int) $group['id'] || $request['state'] !== 'pending'
            || strtotime((string) $request['expires_at'] . ' UTC') <= time()) {
            throw new GroupException('SAME_COMPANY_REQUEST_NOT_FOUND', 404, 'Demande inconnue, échue ou déjà traitée.');
        }

        return [$group];
    }

    /** @return array<string, mixed> le groupe dont ce site est le SIÈGE, module actif @throws GroupException */
    private function headGroup(string $site): array
    {
        $group = $this->groups->ofSite($site);
        if ($group === null || $group['head_tenant_code'] !== $site) {
            throw new GroupException('GROUP_HEAD_ONLY', 403, 'Seul le siège d\'un groupe gère ce lien.');
        }
        if (!$this->modules->isEnabled(ModuleAccessService::MULTI_SHOP)) {
            throw new GroupException('MODULE_INACTIVE', 403, 'Le module Multi-boutiques n\'est pas actif sur ce site.', ['module' => 'module.multi_shop']);
        }

        return $group;
    }

    /**
     * Journal du groupe (base maître) ET des deux sites (`content_audit_log`, lu par « Accès extérieurs ») : la boutique et le siège voient
     * chaque pose, confirmation, refus et retrait. Un journal de site illisible n'arrête pas le geste (la base maître fait foi) : il est signalé.
     *
     * @param array<string, mixed> $extra
     */
    private function trace(int $groupId, string $event, string $shop, string $side, User $by, array $extra = []): void
    {
        $who = self::who($by);
        $this->groups->event($groupId, $event, $shop, ['side' => $side, 'consentVersion' => self::CONSENT_VERSION] + $extra, $who);
        $group = $this->groups->find($groupId);
        foreach (array_unique([$shop, (string) ($group['head_tenant_code'] ?? '')]) as $code) {
            $dbname = $code === '' ? null : $this->dbname($code);
            if ($dbname === null) {
                continue;
            }
            try {
                $this->databases->pdo($dbname)->prepare('INSERT INTO content_audit_log (created_at, user_identifier, tenant, resource, resource_id, action, fields, after_state) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), mb_substr(sprintf('Groupe %s, %s', (string) ($group['name'] ?? ''), $who), 0, 180), $code,
                        EntryService::JOURNAL_RESOURCE, (string) $groupId, self::JOURNAL_ACTION, 'same_company',
                        json_encode(['mode' => EntryService::MODE_GROUP, 'group' => (string) ($group['name'] ?? ''), 'event' => $event, 'shop' => $shop, 'side' => $side], \JSON_UNESCAPED_UNICODE)]);
            } catch (\Throwable $e) {
                $this->logger->error('Groupe : lien « même entreprise » non tracé au journal d\'un site (' . $e::class . ').');
            }
        }
    }

    private function dbname(string $code): ?string
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT dbname FROM tenants WHERE code = ?');
        $stmt->execute([$code]);
        $db = $stmt->fetchColumn();

        return is_string($db) && $db !== '' ? $db : null;
    }

    private function name(string $code): string
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT name FROM tenants WHERE code = ?');
        $stmt->execute([$code]);
        $name = $stmt->fetchColumn();

        return is_string($name) && $name !== '' ? $name : $code;
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
