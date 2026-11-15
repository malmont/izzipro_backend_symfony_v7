<?php

namespace App\Import;

use App\Entity\User;
use App\Import\Message\RunCustomerImportMessage;
use App\Import\Message\SendImportInvitationsMessage;
use App\Import\Shopify\ShopifyCustomerCsv;
use App\Services\CaisseService\StoreClock;
use App\Services\CheckoutService\CheckoutCustomerService;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Site\SiteName;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lot IMP-2 (15/11/2026, `docs/import-boutique.md` § 9) : import des CLIENTS d'une boutique existante, avec leur consentement.
 *
 * Même circuit que le catalogue : le fichier se dépose, s'analyse sans rien écrire, se confirme, puis s'exécute en tâche de fond.
 * Chaque client du fichier devient un compte « invité par import » (`ImportGuests`) : pas de mot de passe, aucune connexion, aucune
 * appartenance, AUCUN courriel. Le commerçant décide ensuite d'envoyer les invitations (`invite`) : un lien par invité, une seule fois,
 * jamais de relance. Le client accepte ou refuse par ce lien (`ImportInvitationService`).
 *
 * Données personnelles : ne sont repris que le prénom, le nom, le courriel, le téléphone et l'adresse par défaut ; le rapport ne
 * garde que des numéros de ligne et des raisons ; le fichier est supprimé du serveur dès la fin de l'exécution ; un invité qui n'a
 * pas accepté est effacé `ImportGuests::ERASE_DAYS` jours après l'import. Le consentement aux courriels commerciaux n'est noté que
 * si le fichier le dit ET si le commerçant l'atteste à la confirmation (gardé sur l'import, et écrit au journal du site).
 */
class CustomerImportService
{
    public const MAX_BYTES = CatalogImportService::MAX_BYTES;
    /** Un import resté « en cours » plus longtemps est tenu pour interrompu : il peut être annulé, le site n'est pas bloqué */
    public const STALE_MINUTES = 60;
    public const PER_PAGE = 50;
    /** Chemin de la page du lien sur la boutique publique du site */
    public const INVITATION_PATH = '/invitation';
    /** Envois d'affilée en échec avant d'arrêter une série d'invitations (le site ne peut pas envoyer) */
    public const MAX_SEND_FAILURES = 5;
    /** Invitations envoyées par passage : au-delà, le commerçant redemande l'envoi (un serveur d'envoi a souvent un plafond par heure ou par jour) */
    public const MAX_PER_RUN = 300;
    /** Un import de clients déposé et jamais confirmé est abandonné après ce délai, et son fichier supprimé */
    public const ABANDON_DAYS = 7;
    private const TEMPLATE = 'emails/customer_import_invitation.html.twig';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly ImportJobStore $store,
        private readonly ImportGuests $guests,
        private readonly EmailLocale $siteLocale,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly ContentAuditRecorder $audit,
        private readonly EmailSenderService $mailer,
        private readonly TranslatorInterface $translator,
        private readonly TenantPublicHostResolver $hosts,
        private readonly SiteName $siteName,
        private readonly StoreClock $clock,
        private readonly ImportInvitationSwitch $switch,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** Dépose le fichier des clients et l'analyse, sans rien écrire. @return array<string, mixed> la vue de l'import */
    public function upload(?UploadedFile $file, string $source, ?string $by): array
    {
        if ($source !== ImportJobStore::SOURCE_SHOPIFY) {
            throw new ImportException('IMPORT_SOURCE_UNKNOWN', 422);
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new ImportException('IMPORT_FILE_REQUIRED', 422);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ImportException('IMPORT_FILE_TOO_BIG', 422, ['maxBytes' => self::MAX_BYTES]);
        }
        if ($this->store->activeId() !== null) {
            throw new ImportException('IMPORT_ALREADY_ACTIVE', 409, ['importId' => $this->store->activeId()]);
        }
        $stored = bin2hex(random_bytes(16)) . '.csv';
        (new Filesystem())->mkdir($this->directory(), 0750);
        $original = (string) $file->getClientOriginalName();
        $file->move($this->directory(), $stored);
        $path = $this->directory() . '/' . $stored;
        $parsed = (new ShopifyCustomerCsv())->read($path);
        if ($parsed['error'] !== null) {
            @unlink($path);
            throw new ImportException('IMPORT_' . $parsed['error'], 422, $parsed['error'] === ShopifyCustomerCsv::TOO_MANY_ROWS ? ['maxRows' => ShopifyCustomerCsv::MAX_ROWS] : []);
        }
        try {
            $report = $this->report($this->plan($parsed['customers']));
            $id = $this->store->create($source, ImportJobStore::KIND_CUSTOMERS, $original !== '' ? $original : 'clients.csv', $stored, (string) hash_file('sha256', $path), $parsed['rows'], $by);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            @unlink($path); // deux dépôts au même instant : la base n'en garde qu'un
            throw new ImportException('IMPORT_ALREADY_ACTIVE', 409, ['importId' => $this->store->activeId()]);
        } catch (\Throwable $e) {
            @unlink($path); // aucun import n'a été créé : le fichier ne reste pas sur le serveur
            throw $e;
        }
        $this->store->move($id, ImportJobStore::UPLOADED, ImportJobStore::ANALYSED, ['report' => $report, 'analysed_at' => true]);

        return $this->view($this->job($id));
    }

    /**
     * Confirme l'import analysé et le met en file. `attestMarketingConsent: true` : le commerçant atteste qu'il a obtenu le
     * consentement aux courriels commerciaux des clients que le fichier marque comme tels ; sans cette attestation, ce consentement
     * n'est repris pour personne.
     *
     * @param array<string, mixed> $body { attestMarketingConsent?: bool }
     */
    public function confirm(int $id, array $body, ?string $by): array
    {
        $job = $this->job($id);
        if (array_key_exists('attestMarketingConsent', $body) && !is_bool($body['attestMarketingConsent'])) {
            throw new ImportException('VALIDATION_FAILED', 422, ['fields' => ['attestMarketingConsent' => 'MUST_BE_BOOLEAN']]);
        }
        if (($job['report']['customers']['importable'] ?? 0) < 1) {
            throw new ImportException('IMPORT_NOTHING_TO_IMPORT', 409);
        }
        $attested = ($body['attestMarketingConsent'] ?? false) === true;
        // l'attestation est gardée sur l'import lui-même : le journal du site, lui, ne garde ses lignes que 180 jours
        $options = ['attestMarketingConsent' => $attested, 'attestedBy' => $attested ? $by : null, 'attestedAt' => $attested ? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM) : null];
        if (!$this->store->move($id, ImportJobStore::ANALYSED, ImportJobStore::RUNNING, ['options' => $options, 'started_at' => true])) {
            throw new ImportException('IMPORT_NOT_READY', 409, ['status' => $this->job($id)['status']]);
        }
        if ($attested) {
            $this->journal($id, 'attest', ['marketingConsent' => true, 'by' => $by, 'claimed' => (int) ($job['report']['customers']['marketingClaimed'] ?? 0)], ['attestMarketingConsent']);
        }
        try {
            $this->bus->dispatch(new RunCustomerImportMessage($id, (string) $this->tenants->getCurrentTenantCode(), $by));
        } catch (\Throwable $e) {
            // la file est indisponible : l'import revient à « analysé », le gérant pourra confirmer de nouveau
            $this->logger->error(sprintf('[Import] import de clients %d non mis en file (%s)', $id, $e::class));
            $this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::ANALYSED);
            throw new ImportException('IMPORT_QUEUE_UNAVAILABLE', 503);
        }

        return $this->view($this->job($id));
    }

    /**
     * Exécute un import de clients confirmé sur le site courant (worker). Une adresse prise entre-temps est passée ; une écriture en
     * échec est comptée, et si elle a fermé la connexion l'import s'arrête (`IMPORT_INTERRUPTED`) : déposer de nouveau le fichier reprend
     * où il s'est arrêté, sans doublon. Le fichier est supprimé du serveur à la fin, quelle que soit l'issue : il ne contient que des
     * données personnelles. Un import annulé pendant qu'il tourne s'arrête au contrôle suivant.
     */
    public function run(int $id, ?string $by): void
    {
        $job = $this->store->find($id);
        if ($job === null || $job['status'] !== ImportJobStore::RUNNING || $job['kind'] !== ImportJobStore::KIND_CUSTOMERS) {
            return;
        }
        $result = ['created' => 0, 'withAddress' => 0, 'marketingClaimed' => 0, 'skipped' => 0, 'failed' => 0, 'failures' => []];
        $path = $this->directory() . '/' . $job['stored_name'];
        try {
            $parsed = (new ShopifyCustomerCsv())->read($path);
            if ($parsed['error'] !== null) {
                throw new \RuntimeException('fichier illisible : ' . $parsed['error']);
            }
            $attested = (bool) ($job['options']['attestMarketingConsent'] ?? false);
            $language = $this->siteLocale->language(null);
            $em = $this->emProvider->getEntityManager();
            $done = 0;
            foreach ($this->plan($parsed['customers']) as $item) { // le plan est refait : le site a pu changer depuis l'analyse
                if ($item['status'] !== 'ok') {
                    in_array($item['status'], ['already', 'existing'], true) ? ++$result['skipped'] : null;
                    continue;
                }
                $conn = $em->getConnection();
                $conn->beginTransaction();
                try {
                    // mot de passe inutilisable (aucun hachage ne commence par « ! ») : le client le choisira en acceptant son invitation
                    $user = (new User())->setEmail($item['email'])->setUsername($item['email'])->setFirstname($item['firstname'])->setLastname($item['lastname'])
                        ->setPassword('!' . bin2hex(random_bytes(32)))->setPasswordSet(false)->setIsVerified(false)->setRoles([])->setSignupChannel(User::SIGNUP_IMPORT_INVITE);
                    $em->persist($user);
                    $em->flush();
                    if ($item['address'] !== null) {
                        $address = CheckoutCustomerService::address($item['address'], $user, $item['phone']);
                        $em->persist($address);
                        $em->flush();
                        $user->setPrimaryAddress($address);
                        $em->flush();
                    }
                    $marketing = $attested && $item['marketing'];
                    $this->guests->register((int) $user->getId(), $id, $language, $marketing);
                    $this->store->addItem($id, (string) $job['source'], ImportJobStore::ITEM_CUSTOMER, (int) $user->getId(), $item['ref']);
                    $conn->commit();
                } catch (\Throwable $e) {
                    if ($conn->isTransactionActive()) {
                        $conn->rollBack();
                    }
                    if ($e instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                        ++$result['skipped']; // l'adresse a été prise entre l'analyse et l'écriture : ce compte-là n'est pas touché
                    } else {
                        ++$result['failed'];
                        if (count($result['failures']) < 200) {
                            $result['failures'][] = ['line' => $item['line'], 'code' => 'WRITE_FAILED'];
                        }
                        $this->logger->error(sprintf('[Import] import de clients %d, ligne %d non écrite (%s)', $id, $item['line'], $e::class));
                    }
                    if (!$em->isOpen()) {
                        throw new \RuntimeException('connexion fermée après une écriture en échec (' . $e::class . ')'); // sans l'exception d'origine : son message peut porter une adresse
                    }
                    $em->clear();
                    continue;
                }
                ++$result['created'];
                $result['withAddress'] += $item['address'] !== null ? 1 : 0;
                $result['marketingClaimed'] += $marketing ? 1 : 0;
                $em->clear();
                if (++$done % 50 === 0) {
                    $this->store->saveResult($id, $result); // l'avancement se lit pendant l'exécution
                    if (($this->store->find($id)['status'] ?? null) !== ImportJobStore::RUNNING) {
                        return; // annulé entre-temps (import tenu pour interrompu) : on n'écrit plus rien sous un import annulé
                    }
                }
            }
            $this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::DONE, ['result' => $result, 'finished_at' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Import] import de clients %d arrêté (%s)', $id, $e::class));
            $this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::FAILED, ['result' => $result, 'error' => 'IMPORT_INTERRUPTED', 'finished_at' => true]);
        } finally {
            @unlink($path);
        }
    }

    /**
     * « Envoyer les invitations » : le commerçant décide d'écrire aux clients repris par cet import. Chaque invité reçoit UN courriel,
     * une seule fois ; ceux qui l'ont déjà reçu ne le reçoivent pas de nouveau. L'envoi se fait en tâche de fond.
     *
     * @return array{importId: int, queued: int}
     */
    public function invite(int $id, ?string $by): array
    {
        $job = $this->customersJob($id);
        if (!$this->switch->isOpen()) {
            throw new ImportException('IMPORT_INVITATIONS_NOT_OPEN', 409); // interrupteur de la plateforme éteint : aucune invitation ne part
        }
        if (!in_array($job['status'], [ImportJobStore::DONE, ImportJobStore::FAILED], true)) {
            throw new ImportException('IMPORT_NOT_READY', 409, ['status' => $job['status']]);
        }
        $queued = count($this->guests->toInvite($id));
        if ($queued === 0) {
            throw new ImportException('IMPORT_NOTHING_TO_INVITE', 409);
        }
        if ($this->link('x', 'fr') === null) {
            throw new ImportException('IMPORT_INVITATION_LINK_UNKNOWN', 409); // aucune adresse publique connue pour ce site : le lien ne mènerait nulle part
        }
        try {
            $this->bus->dispatch(new SendImportInvitationsMessage($id, (string) $this->tenants->getCurrentTenantCode()));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Import] invitations de l\'import %d non mises en file (%s)', $id, $e::class));
            throw new ImportException('IMPORT_QUEUE_UNAVAILABLE', 503);
        }
        $this->journal($id, 'invite', ['queued' => $queued, 'by' => $by], ['invitations']);

        return ['importId' => $id, 'queued' => $queued];
    }

    /**
     * Envoie les invitations d'un import (worker) : pour chaque invité jamais invité, pose son jeton puis envoie son courriel, TOUT DE
     * SUITE, par le serveur d'envoi (sans la file des courriels) : « envoyé » veut dire remis au serveur d'envoi. Un envoi en échec rend
     * l'invité à « repris » (rien n'est parti : il pourra être invité plus tard), et `MAX_SEND_FAILURES` échecs d'affilée arrêtent la
     * série. `MAX_PER_RUN` invitations au plus par passage : le commerçant redemande l'envoi pour les suivantes. Ne lève jamais.
     *
     * @return array{sent: int, failed: int}
     */
    public function sendInvitations(int $id): array
    {
        $out = ['sent' => 0, 'failed' => 0];
        if (!$this->switch->isOpen()) {
            $this->logger->warning(sprintf('[Import] invitations de l\'import %d non envoyées : l\'envoi des invitations est éteint', $id));

            return $out; // même si un message traînait dans la file : rien ne part, aucun jeton n'est posé
        }
        try {
            $job = $this->store->find($id);
            if ($job === null || $job['kind'] !== ImportJobStore::KIND_CUSTOMERS) {
                return $out;
            }
            $em = $this->emProvider->getEntityManager();
            $site = $this->siteName->name();
            $streak = 0;
            foreach (array_slice($this->guests->toInvite($id), 0, self::MAX_PER_RUN) as $userId) {
                $invitation = $this->guests->invite($userId);
                $user = $invitation === null ? null : $em->find(User::class, $userId);
                if ($invitation === null || !$user instanceof User) {
                    continue; // déjà invité par un autre passage, accepté ou effacé entre-temps
                }
                $sent = false;
                try {
                    $sent = $this->send($user, $invitation, $site);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('[Import] invitation de l\'import %d en échec (%s)', $id, $e::class));
                }
                if ($sent) {
                    ++$out['sent'];
                    $streak = 0;
                } else {
                    $this->guests->uninvite($userId);
                    ++$out['failed'];
                    if (++$streak >= self::MAX_SEND_FAILURES) {
                        break; // le site ne peut pas envoyer : inutile de poser d'autres jetons
                    }
                }
                $em->clear();
            }
            $result = (array) ($this->store->find($id)['result'] ?? []);
            $before = (array) ($result['invitations'] ?? []);
            $result['invitations'] = ['sent' => (int) ($before['sent'] ?? 0) + $out['sent'], 'failed' => $out['failed'], 'lastRunAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)];
            $this->store->saveResult($id, $result);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Import] invitations de l\'import %d interrompues (%s)', $id, $e::class));
        }

        return $out;
    }

    /**
     * Annule un import de clients : abandonné s'il n'a pas commencé (le fichier est supprimé) ; sinon les invités qui n'ont pas
     * accepté sont effacés, leurs liens ne valent plus rien. Un client qui a accepté reste un client ; un invité qui a passé une
     * commande entre-temps garde son compte (`kept`). Possible tant qu'il reste des invités en attente.
     */
    public function cancel(int $id, ?string $by): array
    {
        $job = $this->customersJob($id);
        if (in_array($job['status'], [ImportJobStore::UPLOADED, ImportJobStore::ANALYSED], true)) {
            if (!$this->store->move($id, (string) $job['status'], ImportJobStore::CANCELLED, ['cancelled_at' => true])) {
                throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $this->job($id)['status']]);
            }
            @unlink($this->directory() . '/' . $job['stored_name']);

            return $this->view($this->job($id));
        }
        if (!$this->cancellable($job)) {
            throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $job['status']]);
        }
        // un import encore « en cours » quitte d'abord cet état : son worker, s'il vit encore, s'arrête au contrôle suivant
        if ($job['status'] === ImportJobStore::RUNNING && !$this->store->move($id, ImportJobStore::RUNNING, ImportJobStore::FAILED, ['error' => 'IMPORT_INTERRUPTED', 'finished_at' => true])) {
            throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $this->job($id)['status']]);
        }
        @unlink($this->directory() . '/' . $job['stored_name']); // un import interrompu a pu laisser son fichier
        // les invités sont effacés AVANT que l'import soit dit « annulé » : si l'effacement s'interrompt, l'annulation se redemande
        $erased = $this->guests->eraseOfJob($id);
        $status = (string) $this->job($id)['status'];
        if (!$this->store->move($id, $status, ImportJobStore::CANCELLED, ['cancelled_at' => true])) {
            throw new ImportException('IMPORT_NOT_CANCELLABLE', 409, ['status' => $this->job($id)['status']]);
        }
        $result = (array) ($this->store->find($id)['result'] ?? []);
        $result['cancel'] = $erased;
        $this->store->saveResult($id, $result);
        $this->journal($id, 'cancel', ['kind' => ImportJobStore::KIND_CUSTOMERS, 'by' => $by] + $erased, ['status']);

        return $this->view($this->job($id));
    }

    /**
     * Passage horaire et lecture de la liste : efface les invités du site dont le délai est passé, et abandonne les imports de clients
     * déposés et jamais confirmés depuis `ABANDON_DAYS` jours (leur fichier, qui ne contient que des données personnelles, est supprimé).
     *
     * @return array{erased: int, kept: int, failed: int, abandoned: int}
     */
    public function purgeDue(): array
    {
        $abandoned = 0;
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $stale = $conn->fetchAllAssociative(
            "SELECT id, status, stored_name FROM import_job WHERE kind = ? AND status IN ('uploaded', 'analysed') AND created_at < NOW() - make_interval(days => ?) ORDER BY id LIMIT 50",
            [ImportJobStore::KIND_CUSTOMERS, self::ABANDON_DAYS]
        );
        foreach ($stale as $job) {
            if ($this->store->move((int) $job['id'], (string) $job['status'], ImportJobStore::CANCELLED, ['cancelled_at' => true])) {
                @unlink($this->directory() . '/' . $job['stored_name']);
                ++$abandoned;
            }
        }

        return $this->guests->eraseDue() + ['abandoned' => $abandoned];
    }

    /** Écrit au journal du site ; un journal en échec n'annule jamais le geste déjà fait */
    private function journal(int $id, string $action, array $after, array $fields): void
    {
        try {
            $this->audit->record('import_job', $id, $action, null, $after, $fields);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Import] journal de l\'import %d non écrit (%s)', $id, $e::class));
        }
    }

    public function show(int $id): array
    {
        return $this->view($this->customersJob($id));
    }

    /** @param array<string, mixed> $job @return array<string, mixed> la vue courte d'un import de clients, pour la liste */
    public function summary(array $job): array
    {
        return $this->view($job, false);
    }

    /**
     * Les clients repris par un import, avec leur état (`imported`, `invited`, `accepted`, `kept`) et leurs dates.
     *
     * @return array{customers: list<array<string, mixed>>, page: int, pages: int, total: int}
     */
    public function customers(int $id, int $page, ?string $state): array
    {
        $this->customersJob($id);
        if ($state !== null && !in_array($state, ImportGuests::STATES, true)) {
            throw new ImportException('VALIDATION_FAILED', 422, ['fields' => ['state' => 'INVALID_VALUE']]);
        }
        $page = max(1, $page);
        [$rows, $total] = $this->guests->page($id, $state, $page, self::PER_PAGE);
        $iso = static fn (?string $at): ?string => $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        return ['customers' => array_map(static fn (array $r): array => [
            'userId' => (int) $r['user_id'], 'email' => (string) $r['email'], 'firstname' => (string) $r['firstname'], 'lastname' => (string) $r['lastname'], 'state' => (string) $r['state'],
            'marketingClaimed' => in_array($r['marketing_claimed'], [true, 't', 'true', 1, '1'], true), 'importedAt' => $iso($r['imported_at']), 'invitedAt' => $iso($r['invited_at']),
            'linkExpiresAt' => $r['state'] === ImportGuests::INVITED ? $iso($r['expires_at']) : null, 'eraseAt' => in_array($r['state'], ImportGuests::PENDING, true) ? $iso($r['erase_at']) : null,
            'acceptedAt' => $iso($r['accepted_at']),
        ], $rows), 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PER_PAGE)), 'total' => $total];
    }

    /** Le fichier des lignes signalées (numéro de ligne et raison, jamais une adresse), pour un tableur */
    public function issuesCsv(int $id): string
    {
        $job = $this->customersJob($id);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['line', 'code'], ';', '"', '');
        foreach (array_merge((array) ($job['report']['issues'] ?? []), (array) ($job['result']['failures'] ?? [])) as $issue) {
            fputcsv($out, [(string) ($issue['line'] ?? ''), CatalogImportService::safeCell((string) ($issue['code'] ?? ''))], ';', '"', '');
        }
        rewind($out);

        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }

    /**
     * Le plan d'un fichier de clients, SANS RIEN ÉCRIRE : pour chaque ligne, ce qu'elle deviendrait.
     * `ok` (à reprendre), `existing` (cette adresse est déjà celle d'un compte du site : il n'est pas touché), `already` (déjà repris
     * par un import précédent et toujours en attente), `duplicate` (adresse déjà vue plus haut dans le fichier), `refused`.
     *
     * @param list<array<string, mixed>> $customers
     * @return list<array<string, mixed>>
     */
    public function plan(array $customers): array
    {
        $emails = [];
        foreach ($customers as $c) {
            $email = mb_strtolower(trim((string) $c['email']));
            if ($email !== '') {
                $emails[$email] = true;
            }
        }
        $taken = $this->taken(array_keys($emails));
        $seen = [];
        $items = [];
        foreach ($customers as $c) {
            $email = mb_strtolower(trim((string) $c['email']));
            $item = ['line' => (int) $c['line'], 'status' => 'ok', 'code' => null, 'email' => $email, 'ref' => (string) $c['ref'] !== '' ? (string) $c['ref'] : hash('sha256', $email),
                'firstname' => mb_substr(trim((string) $c['firstname']), 0, 100), 'lastname' => mb_substr(trim((string) $c['lastname']), 0, 100),
                'phone' => mb_substr(preg_replace('/[^0-9+() .-]/', '', (string) $c['phone']) ?? '', 0, 20), 'marketing' => (bool) $c['marketing'], 'address' => null, 'addressIncomplete' => false];
            if ($email === '') {
                $items[] = ['status' => 'refused', 'code' => 'NO_EMAIL'] + $item;
                continue;
            }
            if (mb_strlen($email) > 180 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
                $items[] = ['status' => 'refused', 'code' => 'EMAIL_INVALID'] + $item;
                continue;
            }
            if (isset($seen[$email])) {
                $items[] = ['status' => 'duplicate', 'code' => 'DUPLICATE_IN_FILE'] + $item;
                continue;
            }
            $seen[$email] = true;
            if (isset($taken[$email])) {
                $items[] = ($taken[$email] ? ['status' => 'already', 'code' => 'ALREADY_IMPORTED'] : ['status' => 'existing', 'code' => 'ALREADY_CUSTOMER']) + $item;
                continue;
            }
            $a = array_map(static fn (mixed $v): string => trim((string) $v), (array) $c['address']);
            if ($a['address1'] !== '' && $a['city'] !== '' && $a['zip'] !== '' && $a['country'] !== '') {
                $item['address'] = ['company' => $a['company'] !== '' ? mb_substr($a['company'], 0, 255) : null, 'address' => mb_substr($a['address1'], 0, 255), 'complement' => $a['address2'] !== '' ? mb_substr($a['address2'], 0, 255) : null,
                    'city' => mb_substr($a['city'], 0, 255), 'province' => $a['province'] !== '' ? mb_substr($a['province'], 0, 100) : null, 'zip' => mb_substr($a['zip'], 0, 10), 'country' => mb_substr($a['country'], 0, 100),
                    'phone' => mb_substr(preg_replace('/[^0-9+() .-]/', '', $a['phone'] !== '' ? $a['phone'] : $item['phone']) ?? '', 0, 20)];
            } elseif (implode('', [$a['address1'], $a['city'], $a['zip']]) !== '') {
                $item['addressIncomplete'] = true; // le compte est repris, sans cette adresse (rue, ville, code postal et pays sont exigés)
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Le rapport d'analyse : des comptes et, pour chaque ligne signalée, son numéro et sa raison. Aucune adresse, aucun nom.
     *
     * @param list<array<string, mixed>> $plan
     * @return array<string, mixed>
     */
    public function report(array $plan): array
    {
        $count = static fn (callable $test): int => count(array_filter($plan, $test));
        $issues = [];
        foreach ($plan as $item) {
            if ($item['status'] !== 'ok') {
                $issues[] = ['line' => $item['line'], 'code' => (string) $item['code']];
            } elseif ($item['addressIncomplete']) {
                $issues[] = ['line' => $item['line'], 'code' => 'ADDRESS_INCOMPLETE'];
            }
        }

        return ['customers' => [
            'total' => count($plan), 'importable' => $count(static fn (array $i): bool => $i['status'] === 'ok'),
            'alreadyCustomer' => $count(static fn (array $i): bool => $i['status'] === 'existing'), 'alreadyImported' => $count(static fn (array $i): bool => $i['status'] === 'already'),
            'duplicates' => $count(static fn (array $i): bool => $i['status'] === 'duplicate'), 'refused' => $count(static fn (array $i): bool => $i['status'] === 'refused'),
            'withAddress' => $count(static fn (array $i): bool => $i['status'] === 'ok' && $i['address'] !== null),
            'marketingClaimed' => $count(static fn (array $i): bool => $i['status'] === 'ok' && $i['marketing']),
        ], 'issues' => $issues, 'eraseDays' => ImportGuests::ERASE_DAYS, 'linkDays' => ImportGuests::LINK_DAYS];
    }

    /**
     * Les adresses déjà prises sur le site, comparées sans la casse : adresse => vrai si c'est un invité en attente, faux si c'est un
     * autre compte.
     *
     * @param list<string> $emails déjà en minuscules
     * @return array<string, bool>
     */
    private function taken(array $emails): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $taken = [];
        foreach (array_chunk($emails, 500) as $chunk) {
            $rows = $conn->fetchAllAssociative(
                "SELECT LOWER(u.email) AS email, (u.password_set = false AND u.is_verified = false AND u.roles::text = '[]' AND EXISTS (SELECT 1 FROM import_customer_invitation i WHERE i.user_id = u.id AND i.state IN ('imported', 'invited'))) AS pending FROM \"user\" u WHERE LOWER(u.email) IN (?)",
                [$chunk], [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
            foreach ($rows as $row) {
                $pending = in_array($row['pending'], [true, 't', 'true', 1, '1'], true);
                $taken[(string) $row['email']] = ($taken[(string) $row['email']] ?? true) && $pending; // deux comptes à la casse près : un vrai compte l'emporte
            }
        }

        return $taken;
    }

    /**
     * Le courriel d'invitation : sobre, sans aucune promotion (texte validé par le propriétaire). Deux liens vers la page de la boutique
     * publique : rouvrir le compte, ou faire effacer tout de suite ce qui a été repris.
     *
     * @param array{token: string, expiresAt: string, eraseAt: string} $invitation
     */
    private function send(User $user, array $invitation, string $site): bool
    {
        $language = $this->siteLocale->language(null);
        $link = $this->link($invitation['token'], $language);
        if ($link === null) {
            return false;
        }
        $zone = $this->clock->timezone()->getName(); // le jour écrit est celui du magasin
        $day = static function (string $at) use ($language, $zone): string {
            $written = (new \IntlDateFormatter($language === 'en' ? 'en_CA' : 'fr_CA', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, null, $language === 'en' ? 'MMMM d, y' : 'd MMMM y'))->format(new \DateTimeImmutable($at, new \DateTimeZone('UTC')));

            return $written === false ? substr($at, 0, 10) : $written;
        };
        $t = fn (string $key, array $params = []): string => $this->translator->trans('customer_import_invitation.' . $key, $params, 'emails', $language);
        $params = ['%site%' => $site, '%expires%' => $day($invitation['expiresAt']), '%erase%' => $day($invitation['eraseAt'])];
        $name = trim((string) $user->getFirstname());
        $code = (string) $this->tenants->getCurrentTenantCode();

        return $this->mailer->sendTemplatedEmail((string) $user->getEmail(), $t('subject', $params), self::TEMPLATE, [
            'title' => $t('title', $params), 'greeting' => $name !== '' ? $t('greeting', ['%name%' => $name]) : $t('greeting_plain'), 'message' => $t('message', $params),
            'button' => $t('button'), 'link' => $link, 'afterButton' => $t('after_button'), 'validity' => $t('validity', $params), 'declineIntro' => $t('decline_intro'),
            'declineLabel' => $t('decline_label'), 'declineLink' => $this->link($invitation['token'], $language, true), 'once' => $t('once'), 'siteLine' => $site,
        ], $language, (string) ($this->hosts->describe($code)['host'] ?? $code), null, null, [], true); // envoi direct : le résultat est celui de la remise au serveur d'envoi
    }

    /**
     * L'adresse de la page du lien sur la boutique publique du site, ou null si le site n'a pas d'adresse publique connue. Le jeton est
     * dans le FRAGMENT de l'adresse (`#token=`) : un fragment n'est envoyé à aucun serveur, ni journalisé, ni transmis par un Referer.
     */
    private function link(string $token, string $language, bool $decline = false): ?string
    {
        $code = (string) $this->tenants->getCurrentTenantCode();
        $host = $code !== '' ? trim((string) ($this->hosts->describe($code)['host'] ?? '')) : '';

        return $host === '' ? null : sprintf('https://%s%s?%s#token=%s', $host, self::INVITATION_PATH, http_build_query(['locale' => $language] + ($decline ? ['decline' => 1] : [])), $token);
    }

    /** @param array<string, mixed> $job */
    private function cancellable(array $job): bool
    {
        if (in_array($job['status'], [ImportJobStore::UPLOADED, ImportJobStore::ANALYSED], true)) {
            return true;
        }
        $started = $job['started_at'] !== null ? new \DateTimeImmutable((string) $job['started_at'], new \DateTimeZone('UTC')) : null;
        if ($job['status'] === ImportJobStore::RUNNING) {
            return $started !== null && $started < new \DateTimeImmutable('-' . self::STALE_MINUTES . ' minutes');
        }
        if (!in_array($job['status'], [ImportJobStore::DONE, ImportJobStore::FAILED], true)) {
            return false;
        }
        $counts = $this->guests->countsOf((int) $job['id']);

        return $counts[ImportGuests::IMPORTED] + $counts[ImportGuests::INVITED] > 0;
    }

    /** @return array<string, mixed> */
    private function job(int $id): array
    {
        return $this->store->find($id) ?? throw new ImportException('IMPORT_NOT_FOUND', 404);
    }

    /** @return array<string, mixed> un import de CLIENTS (un import de catalogue n'a pas ces gestes) */
    private function customersJob(int $id): array
    {
        $job = $this->job($id);
        if ($job['kind'] !== ImportJobStore::KIND_CUSTOMERS) {
            throw new ImportException('IMPORT_NOT_FOUND', 404);
        }

        return $job;
    }

    /** @param array<string, mixed> $job */
    private function view(array $job, bool $full = true): array
    {
        $iso = static fn (?string $at): ?string => $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $report = (array) ($job['report'] ?? []);
        $guests = $this->guests->countsOf((int) $job['id']);
        $view = [
            'id' => $job['id'], 'source' => $job['source'], 'kind' => $job['kind'], 'status' => $job['status'], 'fileName' => $job['file_name'], 'rows' => $job['row_count'],
            'createdAt' => $iso($job['created_at']), 'analysedAt' => $iso($job['analysed_at']), 'startedAt' => $iso($job['started_at']), 'finishedAt' => $iso($job['finished_at']), 'cancelledAt' => $iso($job['cancelled_at']),
            'cancellable' => $this->cancellable($job), 'cancellableUntil' => null, 'error' => $job['error'], 'options' => $job['options'],
            // faux tant que la plateforme n'a pas ouvert l'envoi des invitations : l'application ne propose pas « Envoyer les invitations »
            'invitationsOpen' => $this->switch->isOpen(),
            // les clients repris par cet import, par état ; `invitable` : ceux à qui une invitation peut encore partir
            'guests' => $guests + ['invitable' => in_array($job['status'], [ImportJobStore::DONE, ImportJobStore::FAILED], true) ? count($this->guests->toInvite((int) $job['id'])) : 0],
        ];
        if ($full) {
            $issues = (array) ($report['issues'] ?? []);
            $view['report'] = $report === [] ? null : ['issues' => array_slice($issues, 0, 100), 'issuesTotal' => count($issues)] + $report;
            $view['result'] = $job['result'];
        } else {
            $view['summary'] = $report === [] ? null : ['customers' => $report['customers'] ?? null];
        }

        return $view;
    }

    private function directory(): string
    {
        $code = preg_replace('/[^a-z0-9_-]/i', '', (string) $this->tenants->getCurrentTenantCode()) ?: 'site';

        return $this->projectDir . '/var/storage/private_media/imports/' . $code;
    }
}
