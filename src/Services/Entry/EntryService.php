<?php

namespace App\Services\Entry;

use App\Entity\User;
use App\Security\AgencyRoles;
use App\Security\Entry\EntryContext;
use App\Security\Entry\ExternalOperator;
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
    public const CHECK_TTL = 60;
    public const MODE_AGENCY = 'agency';
    public const MODE_SUPER_ADMIN = 'super_admin';
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
        $target = trim($target);
        if ($home === '' || $account->getId() === null) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'home');
        }
        if ($target === '' || strcasecmp($target, $home) === 0) {
            throw new EntryException('ENTRY_SAME_SITE', 422);
        }
        // le renouvellement EXIGE une session encore vivante sur le site d'attache : un jeton de renouvellement valable (la déconnexion
        // le révoque ; un jeton d'accès encore lisible après la déconnexion n'ouvre plus d'entrée)
        if (!$this->liveSession((string) $account->getEmail())) {
            throw new EntryException('ENTRY_FORBIDDEN', 403, 'session');
        }
        $site = $this->agencies->tenant($target);
        $roles = $account->getRoles();
        $expires = new \DateTimeImmutable('+' . self::TTL . ' seconds');
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
            $label = $platform['email'];
            $mode = self::MODE_SUPER_ADMIN;
            $agencyName = null;
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
        $token = $this->encoder->encode($payload + ['tenant_code' => $site['code'], 'entry' => ['id' => $id, 'mode' => $mode, 'home' => $home], 'exp' => $expires->getTimestamp()]);

        return ['token' => $token, 'expiresAt' => $expires->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM), 'mode' => $mode,
            'site' => $site['code'], 'entry' => $id, 'operator' => $label];
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
     * @return array{reason: ?string, id: int, mode: string, home: string, agencyId: ?int, agencyName: ?string, firstName: string, origin: ?string}
     */
    private function evaluate(array $payload, ?string $current): array
    {
        $entry = $payload['entry'] ?? null;
        $id = is_array($entry) && is_int($entry['id'] ?? null) ? $entry['id'] : 0;
        $mode = is_array($entry) ? ($entry['mode'] ?? null) : null;
        $home = is_array($entry) ? ($entry['home'] ?? null) : null;
        $refused = static fn (string $reason): array => ['reason' => $reason, 'id' => $id, 'mode' => (string) $mode, 'home' => (string) $home, 'agencyId' => null, 'agencyName' => null, 'firstName' => '', 'origin' => null];
        if ($id <= 0 || !in_array($mode, [self::MODE_AGENCY, self::MODE_SUPER_ADMIN], true) || !is_string($home) || $current === null || $current === ''
            || ($payload['tenant_code'] ?? null) !== $current
            || ($mode === self::MODE_AGENCY && ($payload['username'] ?? null) !== 'entry:' . $id)
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
            'firstName' => (string) $row['first_name'], 'origin' => $link['origin']];
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
                    self::JOURNAL_ACTION, 'mode', json_encode(['mode' => $mode, 'agency' => $agencyName, 'firstName' => $mode === self::MODE_AGENCY ? $firstName : null], \JSON_UNESCAPED_UNICODE)]);
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
