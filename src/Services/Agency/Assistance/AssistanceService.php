<?php

namespace App\Services\Agency\Assistance;

use App\Entity\User;
use App\Services\Agency\AgencyRepository;
use Psr\Log\LoggerInterface;

/**
 * L'ACCÈS D'ASSISTANCE D'UNE AGENCE (lot AG-ASSIST, 16/11/2026, `docs/ecrans-et-contextes.md` § 8).
 *
 * Le COMMERÇANT (administrateur du site, jamais l'agence) ouvre une section fermée à l'agence qui gère son site, en lecture seule
 * (`read`, par défaut) ou en lecture et écriture (`write`). La fenêtre dure UNE HEURE à partir de la première requête de l'opérateur de
 * l'agence dans le site ; une demande sans entrée en 24 h expire. Le commerçant la ferme à tout moment. L'agence ne peut ni la demander
 * ni la prolonger. L'état se calcule à la lecture : aucun minuteur.
 */
class AssistanceService
{
    public const WINDOW = 3600;
    public const REQUEST_TTL = 86400;
    public const WAITING = 'waiting';
    public const ACTIVE = 'active';
    public const ENDED = 'ended';
    public const EXPIRED = 'expired';
    public const CLOSED = 'closed';
    private const LIVE_AGENCY = ['trial', 'active'];

    public function __construct(
        private readonly AssistanceGrantRepository $grants,
        private readonly AgencyRepository $agencies,
        private readonly AssistanceMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * La demande du commerçant. Le contrôleur a déjà vérifié que c'est un compte DU site (ni opérateur extérieur, ni jeton d'entrée).
     *
     * @return array<string, mixed> la demande, telle que la lit l'écran du commerçant (+ `notified` : le nombre de courriels partis)
     * @throws AssistanceException
     */
    public function request(string $tenantCode, mixed $section, mixed $mode, User $merchant): array
    {
        $section = is_string($section) ? trim($section) : '';
        $mode = $mode === null || $mode === '' ? AssistanceSections::READ : (is_string($mode) ? trim($mode) : '');
        if (!isset(AssistanceSections::SECTIONS[$section])) {
            throw new AssistanceException('ASSISTANCE_SECTION_INVALID', 422, 'section : ' . implode(', ', array_keys(AssistanceSections::SECTIONS)) . ' attendu');
        }
        if (!in_array($mode, AssistanceSections::MODES, true)) {
            throw new AssistanceException('ASSISTANCE_MODE_INVALID', 422, 'mode : read ou write attendu');
        }
        if (!in_array($section, AssistanceSections::API_AVAILABLE, true)) {
            throw new AssistanceException('ASSISTANCE_SECTION_UNAVAILABLE', 422, 'Cette section ne s\'ouvre pas encore à l\'agence depuis l\'espace d\'administration du web : elle viendra avec l\'espace d\'administration (EasyAdmin).');
        }
        if ($mode === AssistanceSections::WRITE && !AssistanceSections::SECTIONS[$section]['writable']) {
            throw new AssistanceException('ASSISTANCE_READ_ONLY', 422, 'Cette section s\'ouvre à l\'agence en consultation seulement : décider d\'un constat, le classer ou gérer la notice aux salariés vous reste réservé.');
        }
        $agency = $this->agencies->managing($tenantCode);
        if ($agency === null || !in_array($agency['status'], self::LIVE_AGENCY, true)) {
            throw new AssistanceException('ASSISTANCE_NO_AGENCY', 409, 'Aucune agence ne gère ce site aujourd\'hui.');
        }
        $now = self::now();
        try {
            $row = $this->grants->insert($tenantCode, (int) $agency['id'], $section, $mode, (int) $merchant->getId(), self::who($merchant), $now, $now->modify('+' . self::REQUEST_TTL . ' seconds'));
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new AssistanceException('ASSISTANCE_ALREADY_OPEN', 409, 'Une assistance est déjà ouverte ou en attente pour cette section : la fermer d\'abord.');
            }

            throw $e;
        }
        $notified = 0;
        try {
            $notified = $this->mailer->notify($agency, $row);
        } catch (\Throwable $e) {
            $this->logger->error('Assistance : prévenir l\'agence a échoué (' . $e::class . ').');
        }

        return $this->view($row, (string) $agency['name']) + ['notified' => $notified];
    }

    /** @return list<array<string, mixed>> les demandes du site, les plus récentes d'abord */
    public function list(string $tenantCode): array
    {
        $names = [];
        $out = [];
        foreach ($this->grants->forTenant($tenantCode) as $row) {
            $id = (int) $row['agency_id'];
            $names[$id] ??= (string) ($this->agencies->find($id)['name'] ?? '');
            $out[] = $this->view($row, $names[$id]);
        }

        return $out;
    }

    public function belongs(string $tenantCode, int $id): bool
    {
        return ($this->grants->find($id)['tenant_code'] ?? null) === $tenantCode;
    }

    /** @return array<string, mixed> la demande fermée @throws AssistanceException */
    public function close(string $tenantCode, int $id, User $merchant): array
    {
        $row = $this->grants->find($id);
        if ($row === null || $row['tenant_code'] !== $tenantCode) {
            throw new AssistanceException('ASSISTANCE_NOT_FOUND', 404, 'Demande d\'assistance introuvable.');
        }
        $this->grants->close($id, $tenantCode, 'merchant', self::who($merchant), self::now());

        return $this->view((array) $this->grants->find($id), (string) ($this->agencies->find((int) $row['agency_id'])['name'] ?? ''));
    }

    /**
     * Les demandes en attente et en cours d'une agence, sur tous ses sites (lues de son site d'attache).
     *
     * @return list<array<string, mixed>>
     */
    public function forAgency(int $agencyId): array
    {
        $out = [];
        foreach ($this->grants->openForAgency($agencyId) as $row) {
            $view = $this->view($row, null);
            if (in_array($view['state'], [self::WAITING, self::ACTIVE], true)) {
                $site = $this->agencies->tenant((string) $row['tenant_code']);
                // l'agence ne lit que le prénom de la personne qui demande (jamais son adresse)
                $first = str_contains($view['requestedBy'], '@') ? '' : (string) strtok($view['requestedBy'], ' ');
                $out[] = ['site' => (string) $row['tenant_code'], 'siteName' => (string) ($site['name'] ?? $row['tenant_code'])] + ['requestedBy' => $first] + $view;
            }
        }

        return $out;
    }

    /**
     * L'assistance qui couvre une requête de l'opérateur de l'agence : une demande vivante (en cours, ou en attente) de CETTE agence, qui
     * gère ENCORE ce site, pour l'une des sections données. RIEN n'est écrit ici : la fenêtre ne s'ouvre qu'une fois la requête acceptée
     * par la garde (`start`). Ordre : une demande en cours avant une demande en attente ; pour une écriture, une demande « modifier » d'abord.
     *
     * @param list<string> $sections
     * @return array<string, mixed>|null
     */
    public function covering(string $tenantCode, int $agencyId, array $sections, bool $write = false): ?array
    {
        if ($sections === [] || (int) ($this->agencies->managing($tenantCode)['id'] ?? 0) !== $agencyId) {
            return null; // une agence détachée n'a plus rien sur ce site, même une demande d'avant
        }
        $now = self::now();
        $candidates = [];
        foreach ($this->grants->openFor($tenantCode, $agencyId) as $row) {
            $state = self::state($row, $now);
            if (in_array($row['section'], $sections, true) && in_array($state, [self::ACTIVE, self::WAITING], true)) {
                $candidates[] = [$state === self::ACTIVE ? 0 : 1, $write && $row['mode'] === AssistanceSections::WRITE ? 0 : 1, (int) $row['id'], $row];
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return $candidates[0][3];
    }

    /**
     * La requête est acceptée : une demande en attente démarre maintenant (une seule fois, même si deux requêtes arrivent ensemble).
     *
     * @param array<string, mixed> $grant
     * @return array<string, mixed>
     */
    public function start(array $grant): array
    {
        if ($grant['started_at'] === null) {
            $now = self::now();
            $this->grants->start((int) $grant['id'], $now, $now->modify('+' . self::WINDOW . ' seconds'));
            $grant = $this->grants->find((int) $grant['id']) ?? $grant;
        }

        return $grant;
    }

    /** @param array<string, mixed> $row */
    public static function state(array $row, \DateTimeImmutable $now): string
    {
        if ($row['closed_at'] !== null) {
            return in_array($row['closed_reason'], [self::EXPIRED, self::ENDED], true) ? (string) $row['closed_reason'] : self::CLOSED;
        }
        if ($row['started_at'] === null) {
            return self::utc((string) $row['request_expires_at']) > $now ? self::WAITING : self::EXPIRED;
        }

        return self::utc((string) $row['ends_at']) > $now ? self::ACTIVE : self::ENDED;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function view(array $row, ?string $agencyName): array
    {
        $iso = static fn (?string $at): ?string => $at === null ? null : self::utc($at)->format(\DATE_ATOM);
        $out = [
            'id' => (int) $row['id'], 'section' => (string) $row['section'], 'mode' => (string) $row['mode'], 'state' => self::state($row, self::now()),
            'requestedBy' => (string) $row['requested_by'], 'requestedAt' => $iso($row['requested_at']), 'requestExpiresAt' => $iso($row['request_expires_at']),
            'startedAt' => $iso($row['started_at']), 'endsAt' => $iso($row['ends_at']), 'closedAt' => $iso($row['closed_at']),
            'closedBy' => $row['closed_by'] !== null ? (string) $row['closed_by'] : null,
        ];

        return $agencyName === null ? $out : $out + ['agency' => $agencyName];
    }

    private static function who(User $user): string
    {
        $name = trim($user->getFirstname() . ' ' . $user->getLastname());

        return $name !== '' ? $name : (string) $user->getEmail();
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function utc(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }
}
