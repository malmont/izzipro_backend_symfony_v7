<?php

namespace App\Services\Entry;

use App\Entity\User;
use App\Security\AgencyRoles;
use App\Security\Entry\EntryContext;
use App\Security\Entry\ExternalOperator;
use App\Security\Entry\GroupOperator;
use App\Services\Agency\AgencyRepository;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantEntityManagerProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * L'ENTRÉE DANS LE SITE D'UN AUTRE (lot AG-3b, 16/11/2026, `docs/ecrans-et-contextes.md` § 7).
 *
 * Un compte connecté sur son site d'attache demande l'entrée dans un autre site (`POST /api/me/contexts/enter`). Le lien est vérifié
 * dans la base maître : agence ni suspendue ni fermée et site géré non détaché, ou compte de la plateforme. Le serveur rend un jeton
 * d'entrée de 15 minutes, signé pour LE site visé (claim `tenant_code`), qui porte l'entrée (`entry` : numéro, mode, site d'attache).
 * Aucun compte n'est créé chez le client. Le jeton se renouvelle depuis le site d'attache seulement (on redemande l'entrée) ; un jeton
 * d'entrée n'en obtient jamais un autre.
 *
 * À chaque requête faite avec ce jeton, le lien est RELU (`check`, cache d'une minute au plus) : entrée fermée (déconnexion du site
 * d'attache), rôle retiré, agence suspendue ou fermée, site détaché → refus à la requête suivante (401 `ENTRY_ENDED`).
 *
 * - Mode `agency` : la requête est faite par un OPÉRATEUR EXTÉRIEUR (`ExternalOperator`), que la garde du mode borne aux écrans de
 *   construction.
 * - Mode `super_admin` : la requête est faite par le compte de la plateforme du site visé (il existe sur chaque site), avec tous ses
 *   écrans ; l'entrée est tracée de la même façon.
 */
class EntryService
{
    public const TTL = 900;
    /** Lot AG-3c : une entrée ouverte depuis EasyAdmin vaut 1 heure (la durée d'une session d'EasyAdmin) ; le lien reste relu à chaque requête */
    public const ADMIN_TTL = 3600;
    public const CHECK_TTL = 60;
    public const MODE_AGENCY = 'agency';
    public const MODE_SUPER_ADMIN = 'super_admin';
    /** Lot G-1b : le DIRECTEUR d'un groupe (un administrateur du siège) dans une boutique du groupe */
    public const MODE_GROUP = 'group';
    /** La trace d'une entrée au journal du site visé (`content_audit_log`), lue par la page « Accès extérieurs » */
    public const JOURNAL_RESOURCE = 'external_access';
    public const JOURNAL_ACTION = 'entry';
    private const LIVE_AGENCY = ['trial', 'active'];

    public function __construct(
        private readonly EntryRepository $entries,
        private readonly AgencyRepository $agencies,
        private readonly TenantDatabaseConnector $databases,
        #[Autowire(service: 'lexik_jwt_authentication.encoder')] private readonly JWTEncoderInterface $encoder,
        #[Autowire(service: 'cache.app')] private readonly CacheInterface $cache,
        private readonly EntryContext $context,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\Group\GroupRepository $groups = null,
    ) {
    }

    /**
     * Remet un jeton d'entrée au compte connecté sur son site d'attache.
     *
     * @return array{token: string, expiresAt: string, mode: string, site: string, entry: int, operator: string}
     * @throws EntryException
     */
    public function enter(User $account, string $home, string $target): array
    {
        $this->precheck($account, $home, $target);
        // le renouvellement EXIGE une session encore vivante sur le site d'attache : un jeton de renouvellement valable (la déconnexion
        // le révoque ; un jeton d'accès encore lisible après la déconnexion n'ouvre plus d'entrée)
        if (!$this->liveSession((string) $account->getEmail())) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'session');
        }
        $opened = $this->open($account, $home, $target, self::TTL);
        $token = $this->encoder->encode($opened['payload'] + ['tenant_code' => $opened['site'], 'entry' => ['id' => $opened['entry'], 'mode' => $opened['mode'], 'home' => $home],
            'exp' => $opened['expires']->getTimestamp()]);

        return ['token' => $token, 'expiresAt' => $opened['expires']->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM), 'mode' => $opened['mode'],
            'site' => $opened['site'], 'entry' => $opened['entry'], 'operator' => $opened['label']];
    }

    /**
     * Lot AG-3c : l'entrée ouverte depuis EasyAdmin (bouton « Entrer » du sélecteur de sites). Mêmes vérifications du lien que l'API, mêmes
     * traces des deux côtés ; la preuve de la session est celle d'EasyAdmin (compte connecté sur son site d'attache, code validé depuis
     * moins de 12 heures : vérifié par l'appelant), pas un jeton de renouvellement. Rend de quoi émettre le laissez-passer.
     *
     * @return array{entry: int, mode: string, site: string, operator: string, expiresAt: string, account: ?int, email: ?string}
     * @throws EntryException
     */
    public function enterFromAdmin(User $account, string $home, string $target): array
    {
        $this->precheck($account, $home, $target);
        $opened = $this->open($account, $home, $target, self::ADMIN_TTL);

        return ['entry' => $opened['entry'], 'mode' => $opened['mode'], 'site' => $opened['site'], 'operator' => $opened['label'],
            'expiresAt' => $opened['expires']->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'account' => isset($opened['payload']['id']) ? (int) $opened['payload']['id'] : null, 'email' => isset($opened['payload']['email']) ? (string) $opened['payload']['email'] : null];
    }

    /** @throws EntryException */
    private function precheck(User $account, string $home, string $target): void
    {
        if ($home === '' || $account->getId() === null) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'home');
        }
        $target = trim($target);
        if ($target === '' || strcasecmp($target, $home) === 0) {
            throw new EntryException('ENTRY_SAME_SITE', 422);
        }
    }

    /**
     * Ouvre l'entrée (lien vérifié, ligne de la base maître, trace au journal du site visé).
     *
     * @return array{entry: int, mode: string, site: string, label: string, payload: array<string, mixed>, expires: \DateTimeImmutable}
     * @throws EntryException
     */
    private function open(User $account, string $home, string $target, int $ttl): array
    {
        $target = trim($target);
        $site = $this->agencies->tenant($target);
        $roles = $account->getRoles();
        $expires = new \DateTimeImmutable('+' . $ttl . ' seconds');
        $firstName = trim((string) $account->getFirstname());

        if (PlatformAccessJournal::isPlatformAccount($roles)) {
            if ($site === null) {
                throw new EntryException('ENTRY_SITE_UNKNOWN', 404);
            }
            $platform = $this->platformAccountOn($site['dbname'], (string) $account->getEmail());
            if ($platform === null) {
                throw new EntryException('ENTRY_UNAVAILABLE', 409, 'platform_account');
            }
            $id = $this->entries->open(self::MODE_SUPER_ADMIN, $home, $site['code'], (int) $account->getId(), (string) $account->getEmail(), $firstName, null, null, $expires);
            $payload = ['username' => $platform['email'], 'id' => $platform['id'], 'email' => $platform['email'], 'roles' => $platform['roles']];
            $label = ExternalOperator::platformLabel($firstName); // jamais une adresse au journal du site visé (17/11/2026)
            $mode = self::MODE_SUPER_ADMIN;
            $agencyName = null;
        } elseif ($site !== null && ($group = $this->directedGroup($roles, $home, $site['code'])) !== null) {
            // lot G-1b : le directeur du groupe entre dans une boutique du groupe (avant le rôle d'agence : deux liens séparés, et la
            // boutique du groupe n'est pas gérée par l'agence de ce compte)
            $id = $this->entries->open(self::MODE_GROUP, $home, $site['code'], (int) $account->getId(), (string) $account->getEmail(), $firstName, null, (string) $group['name'], $expires, (int) $group['id']);
            $payload = ['username' => 'entry:' . $id];
            $label = GroupOperator::label((string) $group['name'], $firstName);
            $mode = self::MODE_GROUP;
            $agencyName = (string) $group['name'];
        } elseif (AgencyRoles::has($roles)) {
            $agency = $this->agencies->findByHome($home);
            if ($agency === null || !in_array($agency['status'], self::LIVE_AGENCY, true)) {
                throw new EntryException('ENTRY_FORBIDDEN', 403, 'agency');
            }
            // un site que l'agence ne gère pas se refuse comme un site inconnu : la réponse ne dit pas qu'il existe
            if ($site === null || $this->agencies->site((int) $agency['id'], $site['code']) === null) {
                throw new EntryException('ENTRY_FORBIDDEN', 403, 'site');
            }
            $id = $this->entries->open(self::MODE_AGENCY, $home, $site['code'], (int) $account->getId(), (string) $account->getEmail(), $firstName, (int) $agency['id'], (string) $agency['name'], $expires);
            $payload = ['username' => 'entry:' . $id];
            $label = ExternalOperator::label((string) $agency['name'], $firstName);
            $mode = self::MODE_AGENCY;
            $agencyName = (string) $agency['name'];
        } else {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'role');
        }

        $this->journal($site['dbname'], $site['code'], $label, $id, $mode, $agencyName, $firstName);
        if ($this->cache instanceof \Symfony\Contracts\Cache\TagAwareCacheInterface) {
            $this->cache->invalidateTags([\App\Services\Agency\AgencyClientsService::tag($site['code'])]); // lot AG-3d : « dernière entrée » de « Tous mes clients »
        }

        return ['entry' => $id, 'mode' => $mode, 'site' => $site['code'], 'label' => $label, 'payload' => $payload, 'expires' => $expires];
    }

    /**
     * Lot AG-3c : l'entrée gardée dans une SESSION d'EasyAdmin tient-elle encore sur le site courant ? Même relecture que pour un jeton
     * (ligne de l'entrée à chaque fois, lien gardé une minute au plus). Pose le contexte de la requête si oui ; rend la raison du refus
     * sinon (la session est alors vidée par l'appelant).
     *
     * @param array{id: int, mode: string, home: string, account?: ?int, email?: ?string} $entry
     */
    public function sessionEntry(array $entry, ?string $current): ?string
    {
        $payload = ['entry' => ['id' => $entry['id'] ?? 0, 'mode' => $entry['mode'] ?? null, 'home' => $entry['home'] ?? null], 'tenant_code' => $current];
        $payload += in_array($entry['mode'] ?? null, [self::MODE_AGENCY, self::MODE_GROUP], true) ? ['username' => 'entry:' . ($entry['id'] ?? 0)] : ['id' => $entry['account'] ?? null, 'username' => $entry['email'] ?? null];
        try {
            $state = $this->evaluate($payload, $current);
        } catch (\Throwable $e) {
            $this->logger->warning('Entrée d\'EasyAdmin : lien illisible (' . $e::class . ').');

            return 'unavailable';
        }
        if ($state['reason'] !== null) {
            return $state['reason'];
        }
        $this->context->set(['id' => $state['id'], 'mode' => $state['mode'], 'home' => $state['home']]);

        return null;
    }

    /** Lot AG-3c : « Quitter » l'entrée depuis EasyAdmin, ou la session qui la porte se termine */
    public function endEntry(int $id, string $reason): void
    {
        $this->entries->end($id, $reason);
        $this->cache->delete(self::cacheKey($id));
    }

    /**
     * Le compte d'une requête faite avec un jeton d'entrée, sur le site courant. Le lien est relu ; refusé : `UserNotFoundException`
     * (le pare-feu répond 401, code `ENTRY_ENDED`).
     *
     * @param array<string, mixed> $payload
     */
    public function userFor(array $payload, ?string $current): UserInterface
    {
        $state = $this->evaluate($payload, $current);
        if ($state['reason'] !== null) {
            $this->context->fail($state['reason']);
            throw new UserNotFoundException('Entrée fermée ou jeton d\'entrée mal formé.');
        }
        $entry = ['id' => $state['id'], 'mode' => $state['mode'], 'home' => $state['home']];
        if ($state['mode'] === self::MODE_GROUP) {
            $this->context->set($entry);

            return new GroupOperator($state['id'], (int) $state['groupId'], (string) $state['agencyName'], (string) $state['firstName'], $state['home']);
        }
        if ($state['mode'] === self::MODE_AGENCY) {
            $this->context->set($entry);

            return new ExternalOperator($state['id'], (int) $state['agencyId'], (string) $state['agencyName'], (string) $state['firstName'], $state['home'], (string) $state['origin']);
        }
        // compte de la plateforme du site visé : relu par son numéro, et il doit en être encore un, sous la même adresse
        $user = $this->emProvider->getEntityManager()->find(User::class, (int) $payload['id']);
        if (!$user instanceof User || !PlatformAccessJournal::isPlatformAccount($user->getRoles()) || $user->getEmail() !== ($payload['username'] ?? null)) {
            $this->context->fail('account');
            throw new UserNotFoundException('Compte de la plateforme introuvable sur ce site.');
        }
        $this->context->set($entry);

        return $user;
    }

    /**
     * Pour les lecteurs du jeton HORS du pare-feu (garde de la construction : voir la vitrine fermée comme un compte d'équipe) : un jeton
     * d'entrée vivant, présenté sur son site, vaut `ROLE_ADMIN` ; sinon aucun rôle. Ne lève rien, ne touche pas au contexte de la
     * requête. La garde du mode reste au-dessus : cette réponse n'ouvre aucune route de l'API.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public function constructionRoles(array $payload, ?string $current): array
    {
        try {
            return $this->evaluate($payload, $current)['reason'] === null ? ['ROLE_ADMIN'] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * L'état d'un jeton d'entrée sur le site courant. La ligne de l'entrée est relue À CHAQUE FOIS (fermeture, échéance, site, mode,
     * origine : une déconnexion vaut dès la requête suivante) ; le reste du lien (compte du site d'attache, rôle, agence, site géré) est
     * gardé une minute au plus.
     *
     * @param array<string, mixed> $payload
     * @return array{reason: ?string, id: int, mode: string, home: string, agencyId: ?int, agencyName: ?string, firstName: string, origin: ?string, groupId?: ?int}
     */
    private function evaluate(array $payload, ?string $current): array
    {
        $entry = $payload['entry'] ?? null;
        $id = is_array($entry) && is_int($entry['id'] ?? null) ? $entry['id'] : 0;
        $mode = is_array($entry) ? ($entry['mode'] ?? null) : null;
        $home = is_array($entry) ? ($entry['home'] ?? null) : null;
        $refused = static fn (string $reason): array => ['reason' => $reason, 'id' => $id, 'mode' => (string) $mode, 'home' => (string) $home, 'agencyId' => null, 'agencyName' => null, 'firstName' => '', 'origin' => null];
        if ($id <= 0 || !in_array($mode, [self::MODE_AGENCY, self::MODE_SUPER_ADMIN, self::MODE_GROUP], true) || !is_string($home) || $current === null || $current === ''
            || ($payload['tenant_code'] ?? null) !== $current
            || (in_array($mode, [self::MODE_AGENCY, self::MODE_GROUP], true) && ($payload['username'] ?? null) !== 'entry:' . $id)
            || ($mode === self::MODE_SUPER_ADMIN && (!isset($payload['id']) || !is_numeric($payload['id'])))) {
            return $refused('malformed');
        }
        $row = $this->entries->find($id);
        if ($row === null) {
            return $refused('unknown');
        }
        if ($row['target_tenant_code'] !== $current || $row['mode'] !== $mode || $row['home_tenant_code'] !== $home) {
            return $refused('mismatch');
        }
        if ($row['ended_at'] !== null) {
            return $refused((string) ($row['ended_reason'] ?? 'ended'));
        }
        if (new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            $this->entries->end($id, 'expired');

            return $refused('expired');
        }
        $link = $this->check($id);
        if ($link['reason'] !== null) {
            return $refused($link['reason']);
        }

        return ['reason' => null, 'id' => $id, 'mode' => $mode, 'home' => $home, 'agencyId' => $row['agency_id'], 'agencyName' => $row['agency_name'],
            'firstName' => (string) $row['first_name'], 'origin' => $link['origin'], 'groupId' => $row['group_id'] ?? null];
    }

    /**
     * Le LIEN d'une entrée tient-il toujours (compte du site d'attache, rôle, agence, site géré) ? Relu de la base maître et du site
     * d'attache, gardé une minute au plus. Un lien rompu ferme l'entrée au journal ; un site d'attache illisible refuse sans fermer
     * (gardé quelques secondes seulement).
     *
     * @return array{reason: ?string, origin: ?string}
     */
    public function check(int $id): array
    {
        return $this->cache->get(self::cacheKey($id), function (ItemInterface $item) use ($id): array {
            $item->expiresAfter(self::CHECK_TTL);
            $row = $this->entries->find($id);
            if ($row === null) {
                return ['reason' => 'unknown', 'origin' => null];
            }
            try {
                [$reason, $origin] = $this->broken($row);
            } catch (\Throwable $e) {
                $this->logger->warning('Entrée dans un autre site : lien illisible (' . $e::class . ').');
                $item->expiresAfter(5);

                return ['reason' => 'unavailable', 'origin' => null];
            }
            if ($reason !== null && $row['ended_at'] === null) {
                $this->entries->end($id, $reason);
            }

            return ['reason' => $reason, 'origin' => $origin];
        });
    }

    /** Ferme les entrées ouvertes d'un compte de ce site d'attache (déconnexion) : refusées dès la requête suivante */
    public function endFrom(string $home, int $accountId, string $reason): void
    {
        foreach ($this->entries->endFrom($home, $accountId, $reason) as $id) {
            $this->cache->delete(self::cacheKey($id));
        }
    }

    /** @param array<string, mixed> $row @return array{0: ?string, 1: ?string} la raison du refus (null : le lien tient), l'origine du site géré */
    private function broken(array $row): array
    {
        $home = $this->agencies->tenant((string) $row['home_tenant_code']);
        if ($home === null || $this->agencies->tenant((string) $row['target_tenant_code']) === null) {
            return ['site', null];
        }
        $account = $this->accountOn($home['dbname'], (int) $row['account_id']);
        if ($account === null || mb_strtolower(trim($account['email'])) !== mb_strtolower(trim((string) $row['account_email']))) {
            return ['account', null];
        }
        if ($row['mode'] === self::MODE_SUPER_ADMIN) {
            return [PlatformAccessJournal::isPlatformAccount($account['roles']) ? null : 'role', null];
        }
        if ($row['mode'] === self::MODE_GROUP) {
            // lot G-1b : toujours administrateur du siège, groupe actif dont ce site est encore le siège, module actif au siège, boutique encore
            // dans le groupe (quitter ferme aussi les entrées tout de suite : GroupService)
            if (!in_array('ROLE_ADMIN', $account['roles'], true) || PlatformAccessJournal::isPlatformAccount($account['roles'])) {
                return ['role', null];
            }
            $group = $row['group_id'] !== null ? $this->groups?->find((int) $row['group_id']) : null;
            if ($group === null || $group['status'] !== 'active' || $group['head_tenant_code'] !== $row['home_tenant_code']) {
                return ['group', null];
            }
            if (!$this->multiShopOn($home['dbname'])) {
                return ['module', null];
            }

            return [$this->groups->isOpenShop((int) $group['id'], (string) $row['target_tenant_code']) ? null : 'left', null];
        }
        if (!AgencyRoles::has($account['roles'])) {
            return ['role', null];
        }
        $agency = $row['agency_id'] !== null ? $this->agencies->find((int) $row['agency_id']) : null;
        if ($agency === null || !in_array($agency['status'], self::LIVE_AGENCY, true) || $agency['home_tenant_code'] !== $row['home_tenant_code']) {
            return ['agency', null];
        }
        $managed = $this->agencies->site((int) $agency['id'], (string) $row['target_tenant_code']);

        return $managed === null ? ['detached', null] : [null, (string) $managed['origin']];
    }

    /**
     * Lot G-1b : les groupes ne s'ouvrent qu'aux administrateurs du SIÈGE (jamais au compte de la plateforme, qui a son propre mode), groupe
     * actif, module actif au siège, et vers une boutique ouverte du groupe autre que le siège. Rend le groupe, ou null.
     *
     * @param list<string> $roles
     * @return array<string, mixed>|null
     */
    private function directedGroup(array $roles, string $home, string $target): ?array
    {
        if ($this->groups === null || !in_array('ROLE_ADMIN', $roles, true) || PlatformAccessJournal::isPlatformAccount($roles)) {
            return null;
        }
        $group = $this->groups->ofSite($home);
        if ($group === null || $group['head_tenant_code'] !== $home || $target === $home || !$this->groups->isOpenShop((int) $group['id'], $target)) {
            return null;
        }
        $site = $this->agencies->tenant($home);

        return $site !== null && $this->multiShopOn((string) $site['dbname']) ? $group : null;
    }

    /** Lot G-1b : le module Multi-boutiques est-il actif au siège (lu dans SA base, sans toucher à la connexion de la requête) ? */
    private function multiShopOn(string $dbname): bool
    {
        $value = $this->databases->pdo($dbname)->query('SELECT is_multi_shop_active FROM entreprise ORDER BY id LIMIT 1')->fetchColumn();

        return in_array($value, [true, 't', 'true', 1, '1'], true);
    }

    /**
     * Lot G-1b : une boutique quitte le groupe (ou il est dissous, `$target` null) : les entrées du directeur se ferment TOUT DE SUITE, la
     * requête suivante reçoit 401 `ENTRY_ENDED`.
     */
    public function endForGroup(int $groupId, ?string $target, string $reason): void
    {
        foreach ($this->entries->endForGroup($groupId, $target, $reason) as $id) {
            $this->cache->delete(self::cacheKey($id));
        }
    }

    /** Le compte a-t-il une session vivante sur le site courant (le site d'attache) : un jeton de renouvellement non échu ? */
    private function liveSession(string $email): bool
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE lower(username) = lower(?) AND valid > NOW()', [trim($email)]) > 0;
    }

    /** @return array{email: string, roles: list<string>}|null le compte, lu dans la base de son site, sans toucher à la connexion de la requête */
    private function accountOn(string $dbname, int $id): ?array
    {
        $stmt = $this->databases->pdo($dbname)->prepare('SELECT email, roles FROM "user" WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $roles = is_string($row['roles']) ? json_decode($row['roles'], true) : null;

        return ['email' => (string) $row['email'], 'roles' => is_array($roles) ? array_values(array_filter($roles, 'is_string')) : []];
    }

    /** @return array{id: int, email: string, roles: list<string>}|null le compte de la plateforme du site visé (même adresse) */
    private function platformAccountOn(string $dbname, string $email): ?array
    {
        $stmt = $this->databases->pdo($dbname)->prepare('SELECT id, email, roles FROM "user" WHERE lower(email) = lower(?) ORDER BY id');
        $stmt->execute([trim($email)]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $roles = is_string($row['roles']) ? json_decode($row['roles'], true) : null;
            $roles = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
            if (PlatformAccessJournal::isPlatformAccount($roles)) {
                return ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'roles' => $roles];
            }
        }

        return null;
    }

    /** Trace de l'entrée au journal du site visé, lue par « Accès extérieurs ». Sans elle, pas d'entrée : la trace est des deux côtés. */
    private function journal(string $dbname, string $code, string $label, int $id, string $mode, ?string $agencyName, string $firstName): void
    {
        try {
            $this->databases->pdo($dbname)->prepare('INSERT INTO content_audit_log (created_at, user_identifier, tenant, resource, resource_id, action, fields, after_state) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), mb_substr($label, 0, 180), $code, self::JOURNAL_RESOURCE, (string) $id,
                    self::JOURNAL_ACTION, 'mode', json_encode(['mode' => $mode, 'agency' => $mode === self::MODE_GROUP ? null : $agencyName, 'group' => $mode === self::MODE_GROUP ? $agencyName : null,
                    'firstName' => $mode !== self::MODE_SUPER_ADMIN ? $firstName : null], \JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            $this->logger->error('Entrée dans un autre site : trace non écrite au journal du site visé (' . $e::class . ') : entrée refusée.');
            $this->entries->end($id, 'trace');

            throw new EntryException('ENTRY_UNAVAILABLE', 503, 'trace');
        }
    }

    private static function cacheKey(int $id): string
    {
        return 'site_entry_' . $id;
    }
}
