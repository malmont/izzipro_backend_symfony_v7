<?php

namespace App\Services\Agency;

use App\Services\Agency\Message\BuildAgencySiteMessage;
use App\Services\Agency\Message\ResendAgencySiteInvitationMessage;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantConnectionManager;
use App\Services\TenantPublicHostResolver;
use App\UseCase\PlatformUseCase\PlatformValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Création d'un site par une agence, par une file (lot AG-2, 15/11/2026, `docs/agences-et-import.md` § 8).
 *
 * Le responsable de l'agence demande un site pour un client : code (son adresse), nom, courriel de la personne qui l'administrera.
 * La réponse est immédiate, « en attente » ; un worker fabrique le site, UN SEUL à la fois sur toute la plateforme (la copie ferme un
 * instant le site de référence), puis l'espace d'agence relit l'état : en attente, en création, prêt, en échec.
 *
 * Refus avant la file : agence suspendue ou fermée ; essai terminé ; limite de sites atteinte (les demandes en cours comptent ; une
 * limite vide vaut « illimité ») ; code mal formé, réservé ou déjà pris ; plafond de demandes par 24 heures (garde-fou technique,
 * réglable par agence). À la fin : le site est rattaché (origine « créé »), reçoit ses droits de source « agence », et la personne
 * désignée reçoit un courriel pour choisir son mot de passe d'administrateur. Chaque demande, création, échec, invitation et
 * changement de module est au journal de l'agence.
 */
class AgencySiteRequestService
{
    public const EVENT_REQUESTED = 'site_requested';
    public const EVENT_CREATED = 'site_created';
    public const EVENT_FAILED = 'site_failed';
    public const EVENT_INVITED = 'site_admin_invited';
    public const EVENT_MODULE_OPENED = 'site_module_opened';
    public const EVENT_MODULE_CLOSED = 'site_module_closed';
    public const EVENT_ADMIN_CHANGED = 'site_admin_changed';
    /** Délai entre deux demandes de renvoi de l'invitation d'un même site (chaque renvoi fait partir un courriel de la plateforme) */
    public const INVITATION_DELAY_MINUTES = 10;
    /** Une demande « en création » depuis plus longtemps a perdu son worker : elle passe en échec */
    public const STALE_MINUTES = 15;
    private const LOCALES = ['fr', 'en'];
    /** Attente du verrou de fabrication : 60 essais espacés de 2 secondes */
    private const LOCK_TRIES = 60;

    public function __construct(
        private readonly AgencyRepository $agencies,
        private readonly AgencySiteRequestRepository $requests,
        private readonly AgencySiteBuilder $builder,
        private readonly AgencyEntitlements $rights,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        private readonly TenantDatabaseConnector $connector,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\Platform\Sites\PlatformSiteRequestRepository $platformRequests = null, // lot TYPE-1 : les demandes de la plateforme retiennent aussi leur code
    ) {
    }

    /** @return array{code: string, available: bool, reason: ?string} reason : INVALID | RESERVED | TAKEN | null */
    public function availability(mixed $code): array
    {
        $code = is_string($code) ? mb_strtolower(trim($code)) : '';
        $reason = !AgencySiteCode::valid($code) ? 'INVALID' : (AgencySiteCode::reserved($code) ? 'RESERVED' : ($this->taken($code) || $this->requests->codeRequested($code) || ($this->platformRequests?->codeRequested($code) ?? false) ? 'TAKEN' : null));

        return ['code' => $code, 'available' => $reason === null, 'reason' => $reason];
    }

    /**
     * Enregistre une demande de site et la confie au worker.
     *
     * @param array<string, mixed> $agency
     * @param array<string, mixed> $data { code, name, adminEmail, adminFirstName?, adminLastName?, locale?, country?, province?, currency?, timezone?, modules? }
     * @return array<string, mixed> la demande, « en attente »
     */
    public function request(array $agency, array $data, ?string $by): array
    {
        $this->mustCreate($agency);
        [$code, $name, $email, $options] = $this->clean($data);
        if (AgencySiteCode::reserved($code)) {
            throw new AgencyException('AGENCY_SITE_CODE_RESERVED', 409, ['siteCode' => $code]);
        }
        if ($this->taken($code) || ($this->platformRequests?->codeRequested($code) ?? false)) {
            throw new AgencyException('AGENCY_SITE_CODE_TAKEN', 409, ['siteCode' => $code]);
        }
        $row = $this->requests->open($agency['id'], $code, $name, $email, $options, $by, $agency['max_sites'], $agency['daily_site_requests']);
        if ($row === 'daily') {
            throw new AgencyException('AGENCY_REQUEST_LIMIT', 429, ['perDay' => $agency['daily_site_requests']]);
        }
        if ($row === 'limit') {
            throw new AgencyException('AGENCY_SITE_LIMIT', 409, ['maxSites' => $agency['max_sites']]);
        }
        if (!is_array($row)) {
            throw new AgencyException('AGENCY_SITE_CODE_TAKEN', 409, ['siteCode' => $code]); // demandé au même instant : la base n'en garde qu'une
        }
        $this->agencies->recordEvent($agency['id'], self::EVENT_REQUESTED, $code, ['requestId' => $row['id']], $by);
        try {
            $this->bus->dispatch(new BuildAgencySiteMessage($row['id']));
        } catch (\Throwable $e) {
            // la demande reste « en attente » : le passage planifié la fabriquera
            $this->logger->error(sprintf('[Agences] demande de site %d non confiée au worker (%s)', $row['id'], $e::class));
        }

        return $this->view($row);
    }

    /** @param array<string, mixed> $agency @return list<array<string, mixed>> les demandes de l'agence, de la plus récente à la plus ancienne */
    public function requestsOf(array $agency, int $limit = 50): array
    {
        return array_map(fn (array $row): array => $this->view($row), $this->requests->ofAgency($agency['id'], $limit));
    }

    /** @param array<string, mixed> $agency @return array<string, mixed> une demande de CETTE agence ; 404 sinon */
    public function show(array $agency, int $id): array
    {
        $row = $this->requests->find($id);
        if ($row === null || $row['agency_id'] !== $agency['id']) {
            throw new AgencyException('AGENCY_REQUEST_NOT_FOUND', 404);
        }

        return $this->view($row);
    }

    /**
     * Fabrique le site d'une demande en attente (worker). Sous le verrou de fabrication : une seule copie du site de référence à la
     * fois. Sans le verrou au bout de l'attente, la demande reste en attente : le passage planifié la reprendra.
     */
    public function process(int $id): void
    {
        $row = $this->requests->find($id);
        if ($row === null || $row['status'] !== AgencySiteRequestRepository::PENDING) {
            return;
        }
        if (!$this->waitLock()) {
            $this->logger->warning(sprintf('[Agences] demande de site %d : verrou de fabrication occupé, elle reste en attente', $id));

            return;
        }
        try {
            $this->buildOne($row);
        } finally {
            $this->requests->unlockBuild();
        }
    }

    /**
     * Commande `app:agency:sites:build` : les fabrications interrompues passent en échec, les demandes restées en attente sont
     * fabriquées ICI, dans l'ordre (rattrapage à la main, répétition).
     *
     * @return array{interrupted: int, processed: int}
     */
    public function drain(): array
    {
        $interrupted = $this->failInterrupted();
        $ids = $this->requests->pendingIds();
        foreach ($ids as $id) {
            $this->process($id);
        }

        return ['interrupted' => $interrupted, 'processed' => count($ids)];
    }

    /**
     * Passage horaire de la plateforme : les fabrications interrompues passent en échec ; une demande restée en attente plus de
     * `STALE_MINUTES` minutes (message perdu, verrou occupé) est confiée de nouveau au worker. Rien n'est fabriqué ici.
     *
     * @return array{interrupted: int, requeued: int}
     */
    public function sweep(): array
    {
        $interrupted = $this->failInterrupted();
        $ids = $this->requests->pendingIds(self::STALE_MINUTES);
        foreach ($ids as $id) {
            $this->bus->dispatch(new BuildAgencySiteMessage($id));
        }

        return ['interrupted' => $interrupted, 'requeued' => count($ids)];
    }

    /**
     * Les fabrications interrompues (worker arrêté en cours de route) passent en échec. Le worker a pu tomber PENDANT la copie : le
     * site de référence, fermé le temps de la copie, est rouvert. Il a pu tomber APRÈS : le site existe alors sans être rattaché ; ses
     * droits « agence » sont annulés (aucun droit vivant sur un site que personne ne gère) et le journal le dit à la plateforme, qui
     * regarde ce site. Rien n'est supprimé tout seul.
     */
    private function failInterrupted(): int
    {
        $stale = $this->requests->failStale(self::STALE_MINUTES);
        if ($stale !== []) {
            $this->tenants->reopenTemplate();
        }
        foreach ($stale as $row) {
            $code = (string) $row['tenant_code'];
            $exists = $this->agencies->tenant($code) !== null;
            if ($this->agencies->managing($code) === null) {
                $this->rights->cancelOfAgency($code);
                if ($exists) {
                    $this->rights->project($code);
                }
            }
            $this->agencies->recordEvent($row['agency_id'], self::EVENT_FAILED, $code, ['requestId' => $row['id'], 'error' => 'INTERRUPTED', 'siteExists' => $exists], 'worker');
        }

        return count($stale);
    }

    /**
     * Le responsable demande un nouveau lien pour l'administrateur d'un site que son agence a créé. Le renvoi est fait par le worker
     * (sur le site neuf, avec les accès de la plateforme) ; son issue se lit sur la demande du site.
     *
     * Avec `adminEmail`, l'agence désigne UNE AUTRE personne (la première adresse était fausse, ou déjà celle d'un compte) : possible
     * tant que l'administrateur désigné n'a pas activé son compte ; ensuite, changer d'administrateur est l'affaire du site.
     * Un renvoi par site toutes les `INVITATION_DELAY_MINUTES` minutes : chaque renvoi fait partir un courriel de la plateforme.
     *
     * @param array<string, mixed> $agency
     * @return array<string, mixed> la demande du site
     */
    public function askInvitation(array $agency, string $code, ?string $by, mixed $adminEmail = null): array
    {
        $this->mustBeOpen($agency);
        $this->createdSite($agency, $code);
        $row = $this->requests->readyFor($agency['id'], $code) ?? throw new AgencyException('AGENCY_REQUEST_NOT_FOUND', 404);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $asked = is_string($row['result']['invitationAskedAt'] ?? null) ? new \DateTimeImmutable($row['result']['invitationAskedAt']) : null;
        if ($asked !== null && $asked > $now->modify('-' . self::INVITATION_DELAY_MINUTES . ' minutes')) {
            throw new AgencyException('AGENCY_INVITATION_TOO_SOON', 429, ['retryAfterSeconds' => max(1, $asked->modify('+' . self::INVITATION_DELAY_MINUTES . ' minutes')->getTimestamp() - $now->getTimestamp())]);
        }
        if ($adminEmail !== null && $adminEmail !== '') {
            $email = is_string($adminEmail) ? mb_strtolower(trim($adminEmail)) : '';
            if ($email === '' || mb_strlen($email) > 180 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
                throw new PlatformValidationException([['path' => 'adminEmail', 'message' => 'adresse courriel valide attendue : celle de la personne qui administrera le site']]);
            }
            if ($email !== $row['admin_email']) {
                if ($this->adminActivated($code, (string) $row['admin_email'])) {
                    throw new AgencyException('AGENCY_ADMIN_ACTIVE', 409);
                }
                $this->requests->changeAdmin($row['id'], $email, (string) $row['admin_email']);
                $this->agencies->recordEvent($agency['id'], self::EVENT_ADMIN_CHANGED, $code, ['requestId' => $row['id']], $by);
            }
        }
        $this->requests->markInvitationAsked($row['id'], $now);
        $this->bus->dispatch(new ResendAgencySiteInvitationMessage($row['id'], $by));

        return $this->view($this->requests->find($row['id']) ?? $row);
    }

    /** Renvoie l'invitation (worker) et note son issue sur la demande */
    public function resendInvitation(int $requestId, ?string $by): void
    {
        $row = $this->requests->find($requestId);
        $agency = $row !== null ? $this->agencies->find($row['agency_id']) : null;
        if ($row === null || $agency === null || $row['status'] !== AgencySiteRequestRepository::READY || !in_array($agency['status'], AgencyService::OPEN_STATUSES, true)
            || $this->agencies->site($agency['id'], (string) $row['tenant_code']) === null) {
            return; // le site n'est plus à cette agence, ou l'agence n'est plus ouverte : rien ne part
        }
        $replaced = is_string($row['options']['replacedAdminEmail'] ?? null) ? $row['options']['replacedAdminEmail'] : null;
        $invitation = $this->builder->invite($agency, $row, true, $replaced);
        if ($replaced !== null) {
            $this->requests->clearReplacedAdmin($row['id']);
        }
        $this->requests->setResult($row['id'], ['invitation' => $invitation] + $row['result']);
        $this->agencies->recordEvent($agency['id'], self::EVENT_INVITED, (string) $row['tenant_code'], ['requestId' => $row['id'], 'sent' => $invitation['sent'], 'reason' => $invitation['reason'], 'again' => true], $by);
    }

    /** L'administrateur désigné a-t-il déjà choisi son mot de passe ? Lu dans la base du site, sans changer de site courant */
    public function adminActivated(string $code, string $email): bool
    {
        $site = $this->agencies->tenant($code);
        if ($site === null) {
            return false;
        }
        $stmt = $this->connector->pdo($site['dbname'])->prepare('SELECT is_verified FROM "user" WHERE LOWER(email) = ? AND signup_channel = ?');
        $stmt->execute([$email, \App\Entity\User::SIGNUP_ADMIN_INVITE]);

        return in_array($stmt->fetchColumn(), [true, 't', 'true', 1, '1'], true);
    }

    /**
     * Les modules d'un site créé par l'agence.
     *
     * @param array<string, mixed> $agency
     * @return list<array<string, mixed>>
     */
    public function modules(array $agency, string $code): array
    {
        $this->createdSite($agency, $code);

        return $this->rights->modules($code, $agency);
    }

    /**
     * Ouvre ou ferme un module d'un site créé par l'agence.
     *
     * @param array<string, mixed> $agency
     * @return list<array<string, mixed>> les modules du site
     */
    public function setModule(array $agency, string $code, string $feature, mixed $enabled, ?string $by): array
    {
        if (!is_bool($enabled)) {
            throw new PlatformValidationException([['path' => 'enabled', 'message' => 'booléen attendu']]);
        }
        $this->mustBeOpen($agency);
        $this->createdSite($agency, $code);
        if ($this->rights->set($code, $feature, $enabled, $agency, $by)) {
            $this->agencies->recordEvent($agency['id'], $enabled ? self::EVENT_MODULE_OPENED : self::EVENT_MODULE_CLOSED, $code, ['feature' => $feature], $by);
        }

        return $this->rights->modules($code, $agency);
    }

    /**
     * Le site que cette agence a créé, pour un geste fait dans sa base (lot CONSTRUCTION : état public, ouverture, lien d'aperçu).
     * `$write` : un geste qui change quelque chose, refusé à une agence suspendue.
     *
     * @param array<string, mixed> $agency
     * @return array{code: string, dbname: string}
     */
    public function createdSiteOf(array $agency, string $code, bool $write = false): array
    {
        if ($write) {
            $this->mustBeOpen($agency);
        }
        $this->createdSite($agency, $code);
        $site = $this->agencies->tenant($code) ?? throw new AgencyException('AGENCY_SITE_NOT_ATTACHED', 404, ['tenantCode' => $code]);

        return ['code' => $site['code'], 'dbname' => $site['dbname']];
    }

    /**
     * Une demande telle que les routes la rendent. Dates en ISO 8601 (UTC).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function view(array $row): array
    {
        $iso = static fn (?string $at): ?string => $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $ready = $row['status'] === AgencySiteRequestRepository::READY;
        $described = $ready ? $this->hosts->describe((string) $row['tenant_code']) : null;

        return [
            'id' => $row['id'], 'siteCode' => $row['tenant_code'], 'name' => $row['name'], 'adminEmail' => $row['admin_email'], 'status' => $row['status'], 'errorCode' => $row['error_code'],
            'requestedAt' => $iso($row['requested_at']), 'startedAt' => $iso($row['started_at']), 'finishedAt' => $iso($row['finished_at']),
            'site' => $ready ? ['code' => $row['tenant_code'], 'name' => (string) ($described['name'] ?? $row['name']), 'host' => isset($described['host']) ? (string) $described['host'] : null] : null,
            'invitation' => $ready ? ($row['result']['invitation'] ?? null) : null,
            'invitationAskedAt' => $ready ? ($row['result']['invitationAskedAt'] ?? null) : null,
            'modules' => $ready ? ($row['result']['modules'] ?? null) : null,
            'warnings' => $ready ? ($row['result']['warnings'] ?? []) : [],
        ];
    }

    /** @param array<string, mixed> $row la demande en attente, verrou de fabrication tenu */
    private function buildOne(array $row): void
    {
        $id = $row['id'];
        if (!$this->requests->claim($id)) {
            return; // un autre worker l'a prise
        }
        $code = (string) $row['tenant_code'];
        $by = $row['requested_by'];
        $agency = $this->agencies->find($row['agency_id']);
        $fail = function (string $error) use ($id, $row, $code): void {
            $this->requests->fail($id, $error);
            $this->agencies->recordEvent($row['agency_id'], self::EVENT_FAILED, $code, ['requestId' => $id, 'error' => $error], 'worker');
        };
        // l'agence a pu être suspendue, et le code pris, entre la demande et la fabrication
        if ($agency === null || !in_array($agency['status'], AgencyService::OPEN_STATUSES, true)) {
            $fail('AGENCY_NOT_OPEN');

            return;
        }
        if (!AgencySiteCode::valid($code) || AgencySiteCode::reserved($code) || $this->taken($code)) {
            $fail('SITE_CODE_TAKEN');

            return;
        }
        try {
            $result = $this->builder->build($agency, $row, $by);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Agences] demande de site %d (%s) : fabrication en échec (%s)', $id, $code, $e::class), ['exception' => $e]);
            $fail('BUILD_FAILED');

            return;
        }
        if (!$this->requests->complete($id, $agency['id'], $code, $by, $result)) {
            // la demande n'était plus « en création » (passée en échec entre-temps) : le site que cet appel vient de créer est défait
            $this->builder->discard($code);
            $fail('ATTACH_FAILED');

            return;
        }
        $this->builder->delivered($code);
        $this->agencies->recordEvent($agency['id'], self::EVENT_CREATED, $code, ['requestId' => $id, 'modules' => $result['modules']['opened'], 'warnings' => $result['warnings']], $by);
        $invitation = $this->builder->invite($agency, $row);
        $this->requests->setResult($id, $result + ['invitation' => $invitation]);
        $this->agencies->recordEvent($agency['id'], self::EVENT_INVITED, $code, ['requestId' => $id, 'sent' => $invitation['sent'], 'reason' => $invitation['reason']], 'worker');
    }

    /** Ce code est-il celui d'un site, d'une base ou d'un domaine qui existe déjà ? (tiret et souligné tenus pour équivalents) */
    private function taken(string $code): bool
    {
        $alt = str_replace('-', '_', $code);
        $databases = array_values(array_unique([AgencySiteCode::database($code), 'db_' . $code]));
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT 1 FROM tenants WHERE lower(code) IN (?, ?) OR dbname IN (' . implode(',', array_fill(0, count($databases), '?')) . ') OR lower(custom_domain) = ? LIMIT 1');
        $stmt->execute([$code, $alt, ...$databases, $code]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        foreach ($databases as $database) {
            if ($this->tenants->databaseExists($database)) {
                return true;
            }
        }
        // Un code qui a servi garde dans la base maître ce qui lui était accordé : un site neuf n'hérite ni des droits d'usage vivants
        // ni des clés d'application d'un ancien site du même nom
        $left = $this->tenants->getPdoMaster()->prepare("SELECT (SELECT COUNT(*) FROM tenant_entitlements WHERE tenant_code IN (?, ?) AND status IN ('active', 'grace', 'suspended'))
            + (SELECT COUNT(*) FROM tenant_app_keys WHERE tenant_code IN (?, ?) AND revoked_at IS NULL)");
        $left->execute([$code, $alt, $code, $alt]);

        return (int) $left->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $agency */
    private function mustBeOpen(array $agency): void
    {
        if (!in_array($agency['status'], AgencyService::OPEN_STATUSES, true)) {
            throw new AgencyException('AGENCY_NOT_OPEN', 409, ['status' => $agency['status']]);
        }
    }

    /** Une agence ouverte, dont l'essai n'est pas terminé (décision du propriétaire : pas de création après la fin de l'essai) @param array<string, mixed> $agency */
    private function mustCreate(array $agency): void
    {
        $this->mustBeOpen($agency);
        if ($agency['status'] === 'trial' && $agency['trial_ends_at'] !== null
            && new \DateTimeImmutable((string) $agency['trial_ends_at'], new \DateTimeZone('UTC')) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new AgencyException('AGENCY_TRIAL_ENDED', 409);
        }
    }

    /**
     * Le site que cette agence gère et qu'elle a créé ; refus sinon. Un site rattaché à la main par la plateforme reste inchangé :
     * l'agence ne règle ni ses modules ni ses comptes.
     *
     * @param array<string, mixed> $agency
     */
    private function createdSite(array $agency, string $code): void
    {
        $site = $this->agencies->site($agency['id'], $code);
        if ($site === null) {
            throw new AgencyException('AGENCY_SITE_NOT_ATTACHED', 404, ['tenantCode' => $code]);
        }
        if ($site['origin'] !== 'created') {
            throw new AgencyException('AGENCY_SITE_NOT_CREATED', 409, ['tenantCode' => $code]);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} code, nom, courriel de l'administrateur, options
     */
    private function clean(array $data): array
    {
        $errors = [];
        $code = is_string($data['code'] ?? null) ? mb_strtolower(trim($data['code'])) : '';
        if (!AgencySiteCode::valid($code)) {
            $errors[] = ['path' => 'code', 'message' => '3 à 40 caractères : lettres minuscules, chiffres, tirets ; une lettre d\'abord'];
        }
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160 || preg_match('/[<>]/', $name) === 1) {
            $errors[] = ['path' => 'name', 'message' => 'nom de 2 à 160 caractères attendu, sans < ni >'];
        }
        $email = is_string($data['adminEmail'] ?? null) ? mb_strtolower(trim($data['adminEmail'])) : '';
        if ($email === '' || mb_strlen($email) > 180 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = ['path' => 'adminEmail', 'message' => 'adresse courriel valide attendue : celle de la personne qui administrera le site'];
        }
        $options = [];
        foreach (['adminFirstName', 'adminLastName'] as $field) {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value) || mb_strlen(trim($value)) > 100 || preg_match('/[<>]/', $value) === 1) {
                $errors[] = ['path' => $field, 'message' => '100 caractères au plus, sans < ni >'];
            } else {
                $options[$field] = trim($value);
            }
        }
        $checks = [
            'locale' => static fn (string $v): bool => in_array($v, self::LOCALES, true),
            'country' => static fn (string $v): bool => preg_match('/^[A-Z]{2}$/', $v) === 1,
            'province' => static fn (string $v): bool => preg_match('/^[A-Z]{2,3}$/', $v) === 1,
            'currency' => static fn (string $v): bool => preg_match('/^[A-Z]{3}$/', $v) === 1,
            'timezone' => static fn (string $v): bool => in_array($v, \DateTimeZone::listIdentifiers(), true),
        ];
        foreach ($checks as $field => $valid) {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value) || !$valid($value)) {
                $errors[] = ['path' => $field, 'message' => 'valeur non reconnue'];
            } else {
                $options[$field] = $value;
            }
        }
        if (array_key_exists('modules', $data) && $data['modules'] !== null) {
            $modules = $data['modules'];
            if (!is_array($modules) || !array_is_list($modules) || array_diff($modules, AgencyEntitlements::features()) !== [] || count($modules) !== count(array_unique($modules))) {
                $errors[] = ['path' => 'modules', 'message' => 'liste de modules reconnus attendue'];
            } else {
                $options['modules'] = array_values($modules);
            }
        }
        if ($errors !== []) {
            throw new PlatformValidationException($errors);
        }

        return [$code, $name, $email, $options];
    }

    private function waitLock(): bool
    {
        for ($try = 0; $try < self::LOCK_TRIES; ++$try) {
            if ($this->requests->lockBuild()) {
                return true;
            }
            sleep(2);
        }

        return false;
    }
}
