<?php

namespace App\Services\Platform\Sites;

use App\Services\Agency\AgencyEntitlements;
use App\Services\Agency\AgencyMemberService;
use App\Services\Agency\AgencyRepository;
use App\Services\Agency\AgencyService;
use App\Services\Agency\AgencySiteBuilder;
use App\Services\Agency\AgencySiteCode;
use App\Services\Agency\AgencySiteRequestRepository;
use App\Services\Agency\AgencySiteRequestService;
use App\Services\Platform\Sites\Message\BuildPlatformSiteMessage;
use App\Services\Platform\TenantDatabaseConnector;
use App\Services\TenantPublicHostResolver;
use App\UseCase\PlatformUseCase\PlatformValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Création d'un site AVEC SON TYPE par le super administrateur (lot TYPE-1, 16/11/2026, `docs/agences-et-import.md` § 9).
 *
 * Le type (normal, agence) est un RACCOURCI qui enchaîne des gestes existants, pas un champ enregistré : il reste porté par les liens
 * de la base maître, et les pastilles en découlent. Une seule logique sert la route `POST /api/platform/sites` et l'écran
 * « Plateforme : sites » d'EasyAdmin.
 *
 * Une demande est une ligne de la base maître, écrite AVANT sa première étape, avec ses étapes et l'état de chacune :
 * - `site` : la fabrication d'AG-2 (`AgencySiteBuilder`, sans agence) : copie du site de référence sous le verrou commun des
 *   fabrications, comptes hérités nettoyés, fiche neuve, modules cochés posés comme des accords de la plateforme (sans essai
 *   automatique), site né en construction ;
 * - `invitation` : l'administrateur désigné est invité par courriel (lien de 7 jours) ;
 * - `agency` (type agence) : la fiche d'agence naît avec ce site pour attache, par le geste d'AG-1 ;
 * - `manager` (type agence, case cochée) : le rôle de responsable est donné à l'administrateur QUAND IL ACCEPTE son invitation, pas
 *   avant : l'étape attend (`waiting`) jusque-là.
 *
 * Chaque étape regarde d'abord si son résultat existe : elle se rejoue sans doublon. Une étape qui échoue AVANT que le site existe
 * laisse la demande « en échec » (rien n'a été créé). Une étape qui échoue APRÈS laisse la demande « à compléter », avec l'étape et
 * son code d'erreur : rien n'est défait en silence, jamais de site orphelin que personne ne voit. « Reprendre » rejoue les seules
 * étapes manquantes ; « Abandonner » laisse un site normal, et le dit.
 */
class PlatformSiteService
{
    public const NORMAL = 'normal';
    public const AGENCY = 'agency';
    public const STEP_SITE = 'site';
    public const STEP_INVITATION = 'invitation';
    public const STEP_AGENCY = 'agency';
    public const STEP_MANAGER = 'manager';
    public const TODO = 'todo';
    public const DONE = 'done';
    public const FAILED = 'failed';
    /** Le rôle de responsable attend l'acceptation de l'invitation */
    public const WAITING = 'waiting';
    public const SKIPPED = 'skipped';
    /** Modules cochés par défaut pour un site créé par la plateforme (décision du 16/11/2026) */
    public const DEFAULT_MODULES = ['module.landing', 'module.boutique'];
    public const BADGE_INDEPENDENT = 'independent';
    public const BADGE_AGENCY_HOME = 'agency_home';
    public const BADGE_AGENCY_MANAGED = 'agency_managed';
    public const BADGE_INCOMPLETE = 'creation_incomplete';
    public const BADGES = [self::BADGE_INDEPENDENT, self::BADGE_AGENCY_HOME, self::BADGE_AGENCY_MANAGED, self::BADGE_INCOMPLETE];
    private const LOCALES = ['fr', 'en'];
    /** Attente du verrou de fabrication : 60 essais espacés de 2 secondes (le même verrou que les fabrications d'agence) */
    private const LOCK_TRIES = 60;

    public function __construct(
        private readonly PlatformSiteRequestRepository $requests,
        private readonly AgencySiteRequestRepository $agencyRequests,
        private readonly AgencySiteRequestService $agencySites,
        private readonly AgencySiteBuilder $builder,
        private readonly AgencyService $agencies,
        private readonly AgencyRepository $agencyRepository,
        private readonly AgencyMemberService $members,
        private readonly TenantPublicHostResolver $hosts,
        private readonly TenantDatabaseConnector $connector,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Enregistre la demande, puis la confie au worker.
     *
     * @param array<string, mixed> $data { type, code, name, adminEmail, adminFirstName?, adminLastName?, locale?, modules?, agency?: { code?, name, contactEmail?, isTest?, trialDays?, maxSites?, managerRole? } }
     * @return array<string, mixed> la demande, « en attente »
     */
    public function request(array $data, ?string $by): array
    {
        [$type, $code, $name, $email, $options] = $this->clean($data);
        $availability = $this->agencySites->availability($code); // forme, codes réservés, site, base ou domaine existants, demande en cours (agence ou plateforme)
        if ($availability['reason'] === 'RESERVED') {
            throw new PlatformSiteException('PLATFORM_SITE_CODE_RESERVED', 409, ['siteCode' => $code]);
        }
        if (!$availability['available'] || $this->requests->codeRequested($code)) {
            throw new PlatformSiteException('PLATFORM_SITE_CODE_TAKEN', 409, ['siteCode' => $code]);
        }
        $steps = [self::STEP_SITE => self::TODO, self::STEP_INVITATION => self::TODO];
        if ($type === self::AGENCY) {
            $steps += [self::STEP_AGENCY => self::TODO, self::STEP_MANAGER => ($options['agency']['managerRole'] ?? true) ? self::TODO : self::SKIPPED];
        }
        $row = $this->requests->open($type, $code, $name, $email, $options, $steps, $by);
        if ($row === null) {
            throw new PlatformSiteException('PLATFORM_SITE_CODE_TAKEN', 409, ['siteCode' => $code]); // demandé au même instant : la base n'en garde qu'une
        }
        $this->dispatch($row['id']);

        return $this->view($row);
    }

    /** @return list<array<string, mixed>> les demandes, de la plus récente à la plus ancienne */
    public function all(int $limit = 100): array
    {
        $this->settleManagers();

        return array_map(fn (array $row): array => $this->view($row), $this->requests->latest($limit));
    }

    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        return $this->view($this->requests->find($id) ?? throw new PlatformSiteException('PLATFORM_SITE_REQUEST_NOT_FOUND', 404));
    }

    /**
     * Déroule les étapes non faites d'une demande en attente (worker). La fabrication du site se fait sous le verrou commun : une
     * seule copie du site de référence à la fois sur toute la plateforme. Sans le verrou au bout de l'attente, la demande reste en
     * attente.
     */
    public function process(int $id): void
    {
        $row = $this->requests->find($id);
        if ($row === null || $row['status'] !== PlatformSiteRequestRepository::PENDING) {
            return;
        }
        $steps = $row['steps'];
        if (($steps[self::STEP_SITE] ?? self::TODO) !== self::DONE && !$this->waitLock()) {
            $this->logger->warning(sprintf('[Plateforme] demande de site %d : verrou de fabrication occupé, elle reste en attente', $id));

            return;
        }
        try {
            if (!$this->requests->move($id, [PlatformSiteRequestRepository::PENDING], PlatformSiteRequestRepository::BUILDING, ['started' => true])) {
                return; // un autre worker l'a prise
            }
            $this->run($row);
        } finally {
            $this->agencyRequests->unlockBuild();
        }
    }

    /**
     * « Reprendre » une demande à compléter : ses seules étapes manquantes sont rejouées, par le worker. Avec `$adminEmail`, une AUTRE
     * adresse est désignée pour l'administrateur (la première était fausse, ou déjà celle d'un compte du site neuf) : possible tant
     * que son invitation n'est pas partie ; l'invitation restée sans suite de la première adresse est retirée par l'étape.
     */
    public function resume(int $id, ?string $by, mixed $adminEmail = null): array
    {
        $row = $this->requests->find($id) ?? throw new PlatformSiteException('PLATFORM_SITE_REQUEST_NOT_FOUND', 404);
        if ($row['status'] !== PlatformSiteRequestRepository::INCOMPLETE) {
            throw new PlatformSiteException('PLATFORM_SITE_REQUEST_STATE', 409, ['status' => $row['status']]);
        }
        if ($adminEmail !== null && $adminEmail !== '') {
            $email = is_string($adminEmail) ? mb_strtolower(trim($adminEmail)) : '';
            if ($email === '' || mb_strlen($email) > 180 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
                throw new PlatformValidationException([['path' => 'adminEmail', 'message' => 'adresse courriel valide attendue']]);
            }
            if (($row['steps'][self::STEP_INVITATION] ?? null) === self::DONE) {
                throw new PlatformSiteException('PLATFORM_SITE_REQUEST_STATE', 409, ['status' => $row['status'], 'step' => self::STEP_INVITATION]);
            }
            if ($email !== $row['admin_email']) {
                $this->requests->changeAdmin($id, $email, (string) $row['admin_email']);
            }
        }
        if (!$this->requests->move($id, [PlatformSiteRequestRepository::INCOMPLETE], PlatformSiteRequestRepository::PENDING, ['failed_step' => null, 'error_code' => null])) {
            throw new PlatformSiteException('PLATFORM_SITE_REQUEST_STATE', 409, ['status' => $row['status']]);
        }
        $this->logger->notice(sprintf('[Plateforme] demande de site %d (%s) reprise par %s', $id, $row['tenant_code'], $by ?? '?'));
        $this->dispatch($id);

        return $this->show($id);
    }

    /**
     * « Abandonner » une demande à compléter, ou pas encore commencée : ses étapes manquantes ne seront pas faites. Le site, s'il
     * existe, reste un site normal (ni défait, ni supprimé) ; la demande le dit (`site`).
     */
    public function abandon(int $id, ?string $by): array
    {
        $row = $this->requests->find($id) ?? throw new PlatformSiteException('PLATFORM_SITE_REQUEST_NOT_FOUND', 404);
        if (!$this->requests->move($id, [PlatformSiteRequestRepository::INCOMPLETE, PlatformSiteRequestRepository::PENDING], PlatformSiteRequestRepository::ABANDONED, ['finished' => true])) {
            throw new PlatformSiteException('PLATFORM_SITE_REQUEST_STATE', 409, ['status' => $row['status']]);
        }
        $this->logger->notice(sprintf('[Plateforme] demande de site %d (%s) abandonnée par %s', $id, $row['tenant_code'], $by ?? '?'));

        return $this->show($id);
    }

    /**
     * L'administrateur désigné d'un site vient de choisir son mot de passe (il a accepté son invitation) : si une demande de type
     * agence l'attendait, le rôle de responsable de l'agence lui est donné. Ne lève jamais : l'acceptation n'en dépend pas.
     */
    public function adminActivated(?string $tenantCode, ?string $email): void
    {
        if ($tenantCode === null || $tenantCode === '' || $email === null || $email === '') {
            return;
        }
        try {
            foreach ($this->requests->waitingManagers($tenantCode) as $row) {
                if (mb_strtolower((string) $row['admin_email']) === mb_strtolower($email)) {
                    $this->grantManager($row);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Plateforme] site %s : rôle de responsable non donné à l\'acceptation (%s)', $tenantCode, $e::class));
        }
    }

    /** Rattrapage : les rôles de responsable qui attendaient une acceptation déjà faite sont donnés (lecture de la liste, passage planifié) */
    public function settleManagers(): int
    {
        $given = 0;
        try {
            foreach ($this->requests->waitingManagers() as $row) {
                $site = $this->agencyRepository->tenant((string) $row['tenant_code']);
                if ($site === null) {
                    continue;
                }
                $stmt = $this->connector->pdo($site['dbname'])->prepare('SELECT 1 FROM "user" WHERE lower(email) = lower(?) AND is_verified AND deleted_at IS NULL LIMIT 1');
                $stmt->execute([(string) $row['admin_email']]);
                if ($stmt->fetchColumn() !== false && $this->grantManager($row)) {
                    ++$given;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Plateforme] rattrapage des rôles de responsable en échec (%s)', $e::class));
        }

        return $given;
    }

    /** Commande de rattrapage : les demandes restées en attente sont déroulées ICI, dans l'ordre @return array{processed: int, managers: int} */
    public function drain(): array
    {
        $ids = $this->requests->pendingIds();
        foreach ($ids as $id) {
            $this->process($id);
        }

        return ['processed' => count($ids), 'managers' => $this->settleManagers()];
    }

    /**
     * Les sites de la plateforme avec leurs PASTILLES, calculées depuis les liens de la base maître (jamais un champ « mode ») :
     * `independent`, `agency_home` (site d'attache d'une agence), `agency_managed` (géré par une agence), `creation_incomplete` (une
     * demande de création reste à compléter). Un site peut en porter plusieurs ; `$badge` filtre.
     *
     * @return list<array{code: string, name: string, host: ?string, badges: list<array<string, mixed>>}>
     */
    public function sites(?string $badge = null): array
    {
        if ($badge !== null && !in_array($badge, self::BADGES, true)) {
            throw new PlatformValidationException([['path' => 'badge', 'message' => 'pastille inconnue']]);
        }
        $pdo = $this->agencyRepository->pdo();
        $homes = [];
        foreach ($pdo->query("SELECT home_tenant_code, id, code, name FROM agencies WHERE status <> 'closed'")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $a) {
            $homes[(string) $a['home_tenant_code']] = ['type' => self::BADGE_AGENCY_HOME, 'agencyId' => (int) $a['id'], 'agencyCode' => (string) $a['code'], 'agencyName' => (string) $a['name']];
        }
        $managed = [];
        foreach ($pdo->query("SELECT s.tenant_code, a.id, a.code, a.name FROM agency_sites s JOIN agencies a ON a.id = s.agency_id WHERE s.detached_at IS NULL AND a.status <> 'closed'")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $s) {
            $managed[(string) $s['tenant_code']] = ['type' => self::BADGE_AGENCY_MANAGED, 'agencyId' => (int) $s['id'], 'agencyCode' => (string) $s['code'], 'agencyName' => (string) $s['name']];
        }
        $incomplete = $this->requests->incompleteByCode();
        $out = [];
        foreach ($pdo->query('SELECT code FROM tenants ORDER BY code')->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $code) {
            $code = (string) $code;
            $badges = array_values(array_filter([$homes[$code] ?? null, $managed[$code] ?? null]));
            if ($badges === []) {
                $badges[] = ['type' => self::BADGE_INDEPENDENT];
            }
            if (isset($incomplete[$code])) {
                $badges[] = ['type' => self::BADGE_INCOMPLETE, 'requestId' => $incomplete[$code]];
            }
            if ($badge !== null && !in_array($badge, array_column($badges, 'type'), true)) {
                continue;
            }
            $described = $this->hosts->describe($code);
            $out[] = ['code' => $code, 'name' => (string) ($described['name'] ?? $code), 'host' => isset($described['host']) ? (string) $described['host'] : null, 'badges' => $badges];
        }

        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function view(array $row): array
    {
        $iso = static fn (?string $at): ?string => $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $built = ($row['steps'][self::STEP_SITE] ?? null) === self::DONE;
        $described = $built ? $this->hosts->describe((string) $row['tenant_code']) : null;

        return [
            'id' => $row['id'], 'type' => $row['type'], 'siteCode' => $row['tenant_code'], 'name' => $row['name'], 'adminEmail' => $row['admin_email'], 'status' => $row['status'],
            'steps' => $row['steps'], 'failedStep' => $row['failed_step'], 'errorCode' => $row['error_code'], 'agencyId' => $row['agency_id'],
            'requestedAt' => $iso($row['requested_at']), 'startedAt' => $iso($row['started_at']), 'finishedAt' => $iso($row['finished_at']),
            // le site existe-t-il ? Une demande abandonnée ou à compléter le dit : il reste alors un site normal
            'site' => $built ? ['code' => $row['tenant_code'], 'name' => (string) ($described['name'] ?? $row['name']), 'host' => isset($described['host']) ? (string) $described['host'] : null] : null,
            'invitation' => $row['result']['invitation'] ?? null,
            'modules' => $row['result']['modules'] ?? null,
            'warnings' => $row['result']['warnings'] ?? [],
        ];
    }

    /** @param array<string, mixed> $row la demande, passée « en cours » */
    private function run(array $row): void
    {
        $id = $row['id'];
        $code = (string) $row['tenant_code'];
        $by = $row['requested_by'];
        $steps = $row['steps'];
        $result = $row['result'];
        $agencyId = $row['agency_id'];
        $stop = function (string $step, string $error) use ($id, &$steps, &$result, &$agencyId): void {
            $steps[$step] = self::FAILED;
            $site = ($steps[self::STEP_SITE] ?? null) === self::DONE;
            // avant que le site existe : rien n'a été créé, la demande est en échec ; après : elle est « à compléter »
            $this->requests->move($id, [PlatformSiteRequestRepository::BUILDING], $site ? PlatformSiteRequestRepository::INCOMPLETE : PlatformSiteRequestRepository::FAILED,
                ['steps' => $steps, 'failed_step' => $step, 'error_code' => $error, 'result' => $result, 'agency_id' => $agencyId] + ($site ? [] : ['finished' => true]));
        };

        // 1. le site
        if ($steps[self::STEP_SITE] !== self::DONE) {
            // (la demande elle-même retient son code : le contrôle ne regarde que le site, la base et les demandes d'agence)
            if ($this->agencyRepository->tenant($code) !== null || $this->agencyRequests->codeRequested($code) || $this->builder->databaseExists($code)) {
                $stop(self::STEP_SITE, 'SITE_CODE_TAKEN'); // le code a été pris entre la demande et la fabrication

                return;
            }
            try {
                $result = $this->builder->build(null, $row, $by) + $result;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Plateforme] demande de site %d (%s) : fabrication en échec (%s)', $id, $code, $e::class), ['exception' => $e]);
                $stop(self::STEP_SITE, 'BUILD_FAILED');

                return;
            }
            $this->builder->delivered($code); // le site est livré : plus rien ne le défait
            $steps[self::STEP_SITE] = self::DONE;
            $this->requests->saveSteps($id, $steps, $result);
        }
        // 2. l'invitation de l'administrateur désigné (rejouée : un nouveau lien, l'ancien ne vaut plus)
        if ($steps[self::STEP_INVITATION] !== self::DONE) {
            $replaced = is_string($row['options']['replacedAdminEmail'] ?? null) ? $row['options']['replacedAdminEmail'] : null; // une autre personne vient d'être désignée
            $invitation = $this->builder->invite(null, $row, $steps[self::STEP_INVITATION] === self::FAILED, $replaced);
            $result['invitation'] = $invitation;
            if (!$invitation['sent']) {
                $stop(self::STEP_INVITATION, (string) ($invitation['reason'] ?? 'INVITATION_FAILED'));

                return;
            }
            $steps[self::STEP_INVITATION] = self::DONE;
            $this->requests->saveSteps($id, $steps, $result);
        }
        // 3. type agence : la fiche d'agence, avec ce site pour attache (le geste d'AG-1)
        if (($steps[self::STEP_AGENCY] ?? self::DONE) !== self::DONE) {
            $existing = $this->agencyRepository->findByHome($code);
            if ($existing !== null) {
                $agencyId = (int) $existing['id']; // déjà créée par un passage précédent : rien n'est doublé
            } else {
                $a = (array) ($row['options']['agency'] ?? []);
                try {
                    $agencyId = (int) $this->agencies->create(['code' => $a['code'] ?? $code, 'name' => $a['name'] ?? $row['name'], 'homeTenantCode' => $code, 'contactEmail' => $a['contactEmail'] ?? null,
                        'test' => (bool) ($a['isTest'] ?? false)] + array_intersect_key($a, ['trialDays' => 1, 'maxSites' => 1]), $by)['id'];
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('[Plateforme] demande de site %d (%s) : agence non créée (%s)', $id, $code, $e::class));
                    $stop(self::STEP_AGENCY, $e instanceof \App\Services\Agency\AgencyException ? $e->errorCode : ($e instanceof PlatformValidationException ? 'AGENCY_INVALID' : 'AGENCY_FAILED'));

                    return;
                }
            }
            $steps[self::STEP_AGENCY] = self::DONE;
            $this->requests->saveSteps($id, $steps, $result, $agencyId);
        }
        // 4. type agence : le rôle de responsable attend l'acceptation de l'invitation
        if (($steps[self::STEP_MANAGER] ?? self::DONE) === self::TODO || ($steps[self::STEP_MANAGER] ?? null) === self::FAILED) {
            $steps[self::STEP_MANAGER] = self::WAITING;
        }
        $this->requests->move($id, [PlatformSiteRequestRepository::BUILDING], PlatformSiteRequestRepository::READY, ['steps' => $steps, 'result' => $result, 'agency_id' => $agencyId, 'finished' => true]);
    }

    /** Donne le rôle de responsable de l'agence à l'administrateur désigné. Rend vrai si l'étape est faite. @param array<string, mixed> $row */
    private function grantManager(array $row): bool
    {
        $agency = $row['agency_id'] !== null ? $this->agencyRepository->find((int) $row['agency_id']) : null;
        if ($agency === null) {
            return false;
        }
        $steps = $row['steps'];
        try {
            $this->members->set($agency, (string) $row['admin_email'], 'manager', 'plateforme');
            $steps[self::STEP_MANAGER] = self::DONE;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Plateforme] demande de site %d : rôle de responsable non donné (%s)', $row['id'], $e::class));

            return false;
        }
        $this->requests->saveSteps((int) $row['id'], $steps);

        return true;
    }

    private function dispatch(int $id): void
    {
        try {
            $this->bus->dispatch(new BuildPlatformSiteMessage($id));
        } catch (\Throwable $e) {
            // la demande reste « en attente » : le passage planifié la déroulera
            $this->logger->error(sprintf('[Plateforme] demande de site %d non confiée au worker (%s)', $id, $e::class));
        }
    }

    private function waitLock(): bool
    {
        for ($i = 0; $i < self::LOCK_TRIES; ++$i) {
            if ($this->agencyRequests->lockBuild()) {
                return true;
            }
            sleep(2);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>} type, code, nom, courriel de l'administrateur, options
     */
    private function clean(array $data): array
    {
        $errors = [];
        $type = is_string($data['type'] ?? null) ? $data['type'] : self::NORMAL;
        if (!in_array($type, [self::NORMAL, self::AGENCY], true)) {
            $errors[] = ['path' => 'type', 'message' => 'normal ou agency attendu'];
        }
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
            $errors[] = ['path' => 'adminEmail', 'message' => 'adresse courriel valide attendue'];
        }
        $options = [];
        foreach (['adminFirstName', 'adminLastName'] as $field) {
            $value = $data[$field] ?? '';
            if (!is_string($value) || mb_strlen(trim($value)) > 100 || preg_match('/[<>]/', $value) === 1) {
                $errors[] = ['path' => $field, 'message' => '100 caractères au plus, sans < ni >'];
                continue;
            }
            $options[$field] = trim($value);
        }
        $locale = $data['locale'] ?? null;
        if ($locale !== null && $locale !== '' && !in_array($locale, self::LOCALES, true)) {
            $errors[] = ['path' => 'locale', 'message' => 'fr ou en attendu'];
        }
        $options['locale'] = in_array($locale, self::LOCALES, true) ? $locale : null;
        $modules = $data['modules'] ?? self::DEFAULT_MODULES;
        if (!is_array($modules) || array_diff($modules, AgencyEntitlements::features()) !== [] || array_filter($modules, static fn (mixed $m): bool => !is_string($m)) !== []) {
            $errors[] = ['path' => 'modules', 'message' => 'liste de modules connus attendue'];
            $modules = [];
        }
        $options['modules'] = array_values(array_unique($modules));
        if ($type === self::AGENCY) {
            $a = $data['agency'] ?? null;
            if (!is_array($a)) {
                $errors[] = ['path' => 'agency', 'message' => 'les réglages de l\'agence sont attendus'];
                $a = [];
            }
            $agency = [];
            $agencyName = is_string($a['name'] ?? null) ? trim($a['name']) : '';
            if (mb_strlen($agencyName) < 2 || mb_strlen($agencyName) > 160 || preg_match('/[<>]/', $agencyName) === 1) {
                $errors[] = ['path' => 'agency.name', 'message' => 'nom de 2 à 160 caractères attendu, sans < ni >'];
            }
            $agency['name'] = $agencyName;
            $agencyCode = is_string($a['code'] ?? null) && trim($a['code']) !== '' ? mb_strtolower(trim($a['code'])) : $code;
            if (preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $agencyCode) !== 1) {
                $errors[] = ['path' => 'agency.code', 'message' => '3 à 50 caractères : lettres minuscules, chiffres, tirets'];
            }
            $agency['code'] = $agencyCode;
            $contact = $a['contactEmail'] ?? null;
            if ($contact !== null && $contact !== '' && (!is_string($contact) || mb_strlen($contact) > 180 || filter_var($contact, \FILTER_VALIDATE_EMAIL) === false)) {
                $errors[] = ['path' => 'agency.contactEmail', 'message' => 'adresse courriel valide attendue'];
            }
            $agency['contactEmail'] = is_string($contact) && $contact !== '' ? mb_strtolower($contact) : null;
            foreach (['isTest' => false, 'managerRole' => true] as $flag => $default) {
                if (array_key_exists($flag, $a) && !is_bool($a[$flag])) {
                    $errors[] = ['path' => "agency.$flag", 'message' => 'booléen attendu'];
                }
                $agency[$flag] = is_bool($a[$flag] ?? null) ? $a[$flag] : $default;
            }
            foreach (['trialDays' => [1, 365], 'maxSites' => [1, 1000]] as $number => [$min, $max]) {
                $value = $a[$number] ?? null;
                if ($value === null || $value === '') {
                    continue; // absent : la valeur du forfait d'essai
                }
                if (!is_int($value) || $value < $min || $value > $max) {
                    $errors[] = ['path' => "agency.$number", 'message' => "entier de $min à $max attendu"];
                    continue;
                }
                $agency[$number] = $value;
            }
            if ($agencyCode !== '' && $this->agencyRepository->findByCode($agencyCode) !== null) {
                $errors[] = ['path' => 'agency.code', 'message' => 'ce code d\'agence est déjà pris'];
            }
            $options['agency'] = $agency;
        }
        if ($errors !== []) {
            throw new PlatformValidationException($errors);
        }

        return [$type, $code, $name, $email, $options];
    }
}
