<?php

namespace App\Incident\Services;

use App\Entity\User;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Incident\Repository\PlatformBugReportRepository;
use App\Services\Privacy\PersonalDataMask;
use App\Services\TenantConnectionManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Signalements de la plateforme (lot INC-3, 18/11/2026, `docs/incidents-et-signalements.md`) :
 * - `create` : un membre du PERSONNEL du site (administrateur, responsable de caisse, caissier ; le genre est tiré des rôles, jamais du
 *   corps) signale un problème, avec une capture facultative ; 10 par compte et 30 par site et par jour ; texte masqué ;
 * - `mine` : « Mes signalements », l'état et la réponse PUBLIQUE, jamais la note interne ni la capture ;
 * - `escalate` : un agent de l'Assistance transmet un ticket à l'équipe technique (un seul signalement ouvert par ticket), sans copie du
 *   fil ni des pièces jointes ; `ticketPlatform` rend l'état lu par le commerçant sur son ticket ;
 * - plateforme (super administrateur) : liste, détail, capture, réponse, état, note, rattachement à un incident ; un courriel au
 *   signaleur quand la réponse ou un état final change ; courriel du jour et purge des captures.
 */
class PlatformBugReportService
{
    public const PER_ACCOUNT_DAILY = 10;
    public const PER_SITE_DAILY = 30;
    public const UNANSWERED_HOURS = 48;
    /** Rôles du personnel, du plus large au plus étroit ; un client (ROLE_USER_INTERNET seul) n'en a aucun */
    private const KINDS = ['ROLE_ADMIN' => 'site_admin', 'ROLE_POS_MANAGER' => 'manager', 'ROLE_USER_POS' => 'cashier'];
    /** État lu par le commerçant sur son ticket */
    private const TICKET_STATES = ['received' => 'transmitted', 'in_progress' => 'transmitted', 'fixed' => 'fixed', 'wont_fix' => 'wont_fix'];

    public function __construct(
        private readonly PlatformBugReportRepository $reports,
        private readonly BugReportScreenshotStorage $screenshots,
        private readonly BugReportMailer $mailer,
        private readonly TenantConnectionManager $tenants,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    /**
     * @param array<string, mixed> $fields `{ description, app, appVersion?, screen?, context? (objet ou JSON) }`
     *
     * @return array{report: array<string, mixed>}
     *
     * @throws GestionValidationException 422 `fields`, GestionConflictException 403 NOT_STAFF, 429 BUG_REPORT_LIMIT
     */
    public function create(array $fields, ?UploadedFile $screenshot, User $user): array
    {
        $site = $this->site();
        $kind = self::kind($user->getRoles()) ?? throw new GestionConflictException('NOT_STAFF', 'Le signalement est réservé au personnel du site : un client s\'adresse à l\'Assistance du commerçant.', 403);
        $today = gmdate('Y-m-d 00:00:00');
        if ($this->reports->countSince($site, (int) $user->getId(), $today) >= self::PER_ACCOUNT_DAILY) {
            throw new GestionConflictException('BUG_REPORT_LIMIT', 'Trop de signalements aujourd\'hui pour ce compte.', 429, ['scope' => 'account']);
        }
        if ($this->reports->countSince($site, null, $today) >= self::PER_SITE_DAILY) {
            throw new GestionConflictException('BUG_REPORT_LIMIT', 'Trop de signalements aujourd\'hui pour ce site.', 429, ['scope' => 'site']);
        }
        $errors = [];
        $description = is_string($fields['description'] ?? null) ? trim($fields['description']) : null;
        if ($description === null || $description === '') {
            $errors['description'] = 'REQUIRED';
        } elseif (mb_strlen($description) < 10 || mb_strlen($description) > 2000) {
            $errors['description'] = 'INVALID_LENGTH';
        }
        $app = $fields['app'] ?? null;
        if ($app === null || $app === '') {
            $errors['app'] = 'REQUIRED';
        } elseif (!in_array($app, PlatformIncidentService::APPS, true)) {
            $errors['app'] = 'INVALID_VALUE';
        }
        $version = $fields['appVersion'] ?? null;
        if ($version !== null && $version !== '' && (!is_string($version) || !preg_match('/^[\w.+\-]{1,40}$/', $version))) {
            $errors['appVersion'] = 'INVALID_VALUE';
        }
        $screen = $fields['screen'] ?? null;
        if ($screen !== null && (!is_string($screen) || mb_strlen($screen) > 500)) {
            $errors['screen'] = 'INVALID_VALUE';
        }
        $context = $fields['context'] ?? null;
        if (is_string($context) && $context !== '') {
            $context = json_decode($context, true);
            if (!is_array($context)) {
                $errors['context'] = 'INVALID_VALUE';
            }
        } elseif ($context !== null && $context !== '' && !is_array($context)) {
            $errors['context'] = 'INVALID_VALUE';
        }
        if ($screenshot !== null && ($refusal = BugReportScreenshotStorage::refusal($screenshot)) !== null) {
            $errors['screenshot'] = $refusal;
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $stored = $screenshot !== null ? $this->screenshots->store($screenshot) : null;
        if ($screenshot !== null && $stored === null) {
            throw new GestionValidationException(['screenshot' => 'FORMAT']);
        }
        $id = (int) $this->reports->insert(['tenant' => $site, 'app' => (string) $app, 'appVersion' => is_string($version) && $version !== '' ? $version : null,
            'screen' => is_string($screen) && trim($screen) !== '' ? PersonalDataMask::text(PlatformIncidentService::path($screen), 160) : null,
            'description' => PersonalDataMask::text((string) $description, 2000), 'context' => is_array($context) ? PlatformIncidentService::context($context) : null,
            'kind' => $kind, 'userId' => (int) $user->getId(), 'userHash' => $this->userHash((int) $user->getId()), 'ticketId' => null, 'now' => gmdate('Y-m-d H:i:s')]);
        if ($stored !== null) {
            $this->reports->setScreenshot($id, $stored);
        }

        return ['report' => self::reporterView($this->reports->find($id) ?? [])];
    }

    /** @return array{reports: list<array<string, mixed>>} « Mes signalements » du compte connecté, sur ce site */
    public function mine(User $user): array
    {
        return ['reports' => array_map(self::reporterView(...), $this->reports->mine($this->site(), (int) $user->getId()))];
    }

    /**
     * Un agent de l'Assistance transmet le ticket `$ticketId` (déjà vérifié sur ce site) à l'équipe technique : `{ summary }`.
     *
     * @param array<string, mixed> $body
     *
     * @return array{platform: array{status: string, answer: ?string}}
     *
     * @throws GestionValidationException 422 `fields.summary`, GestionConflictException 409 ALREADY_ESCALATED
     */
    public function escalate(int $ticketId, array $body, User $agent): array
    {
        $summary = is_string($body['summary'] ?? null) ? trim($body['summary']) : null;
        if ($summary === null || $summary === '') {
            throw new GestionValidationException(['summary' => 'REQUIRED']);
        }
        if (mb_strlen($summary) < 20 || mb_strlen($summary) > 2000) {
            throw new GestionValidationException(['summary' => 'INVALID_LENGTH']);
        }
        $site = $this->site();
        $id = $this->reports->insert(['tenant' => $site, 'app' => 'support', 'appVersion' => null, 'screen' => 'Assistance/Ticket',
            'description' => PersonalDataMask::text($summary, 2000), 'context' => null, 'kind' => 'support_agent', 'userId' => (int) $agent->getId(),
            'userHash' => $this->userHash((int) $agent->getId()), 'ticketId' => $ticketId, 'now' => gmdate('Y-m-d H:i:s')]);
        if ($id === null) {
            throw new GestionConflictException('ALREADY_ESCALATED', 'Ce ticket est déjà transmis à l\'équipe technique.', 409);
        }

        return ['platform' => (array) $this->ticketPlatform($ticketId)];
    }

    /** @return array{status: string, answer: ?string}|null l'état de la transmission d'un ticket de ce site, ou null s'il n'a jamais été transmis */
    public function ticketPlatform(int $ticketId): ?array
    {
        $row = $this->reports->forTicket($this->site(), $ticketId);

        return $row === null ? null : ['status' => self::TICKET_STATES[$row['status']], 'answer' => $row['answer']];
    }

    /**
     * @param array{status?: ?string, tenant?: ?string, app?: ?string} $filters
     *
     * @return array{reports: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(array $filters, int $page, int $limit): array
    {
        if (($filters['status'] ?? null) !== null && !in_array($filters['status'], PlatformBugReportRepository::STATUSES, true)) {
            throw new GestionValidationException(['status' => 'INVALID_VALUE']);
        }
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $found = $this->reports->search($filters, $limit, ($page - 1) * $limit);

        return ['reports' => array_map(self::platformView(...), $found['items']), 'total' => $found['total'], 'page' => $page, 'limit' => $limit];
    }

    /** @return array<string, mixed> 404 BUG_REPORT_NOT_FOUND */
    public function get(int $id): array
    {
        return self::platformView($this->reports->find($id) ?? throw new GestionConflictException('BUG_REPORT_NOT_FOUND', 'Signalement introuvable.', 404));
    }

    /**
     * `{ status?, answer?, note?, incidentId? }` ; un courriel au signaleur quand la réponse change ou qu'un état final est posé.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> le signalement et `mailed`
     */
    public function update(int $id, array $body, string $by): array
    {
        $row = $this->reports->find($id) ?? throw new GestionConflictException('BUG_REPORT_NOT_FOUND', 'Signalement introuvable.', 404);
        $errors = [];
        $set = [];
        if (array_key_exists('status', $body) && $body['status'] !== $row['status']) {
            if (!in_array($body['status'], PlatformBugReportRepository::STATUSES, true)) {
                $errors['status'] = 'INVALID_VALUE';
            } else {
                $set['status'] = $body['status'];
            }
        }
        foreach (['answer' => 4000, 'note' => 4000] as $key => $max) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[$key];
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $set[$key] = null;
            } elseif (!is_string($value) || mb_strlen($value) > $max) {
                $errors[$key] = 'INVALID_VALUE';
            } else {
                $set[$key] = trim($value);
            }
        }
        if (array_key_exists('incidentId', $body)) {
            $incident = $body['incidentId'];
            if ($incident === null || $incident === '') {
                $set['incident_id'] = null;
            } elseif (!is_int($incident) && !(is_string($incident) && ctype_digit($incident)) || !$this->reports->incidentExists((int) $incident)) {
                $errors['incidentId'] = 'INVALID_VALUE';
            } else {
                $set['incident_id'] = (int) $incident;
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $answerChanged = array_key_exists('answer', $set) && $set['answer'] !== null && $set['answer'] !== $row['answer'];
        $finalStatus = isset($set['status']) && in_array($set['status'], ['fixed', 'wont_fix'], true);
        $now = gmdate('Y-m-d H:i:s');
        if ($set !== []) {
            $set += ['updated_at' => $now, 'status_changed_by' => mb_substr($by, 0, 255)];
            if ($answerChanged || $finalStatus) {
                $set['answered_at'] = $now;
            }
            $this->reports->update($id, $set);
        }
        $mailed = ($answerChanged || $finalStatus) && $this->mailer->answered($this->reports->find($id) ?? $row);

        return $this->get($id) + ['mailed' => $mailed];
    }

    /** Le fichier de la capture d'un signalement, pour le super administrateur seul ; 404 BUG_REPORT_SCREENSHOT_NOT_FOUND */
    public function screenshotFile(int $id): string
    {
        $row = $this->reports->find($id) ?? throw new GestionConflictException('BUG_REPORT_NOT_FOUND', 'Signalement introuvable.', 404);

        return $this->screenshots->file($row['screenshot_path']) ?? throw new GestionConflictException('BUG_REPORT_SCREENSHOT_NOT_FOUND', 'Aucune capture pour ce signalement.', 404);
    }

    /** @return list<array<string, mixed>> les signalements reçus sans réponse depuis `UNANSWERED_HOURS` heures */
    public function unanswered(): array
    {
        return array_map(self::platformView(...), $this->reports->unanswered(gmdate('Y-m-d H:i:s', time() - self::UNANSWERED_HOURS * 3600)));
    }

    /** Efface les captures plus vieilles que la durée réglée ; rend leur nombre */
    public function purgeScreenshots(): int
    {
        $count = 0;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($this->reports->screenshotsBefore(gmdate('Y-m-d H:i:s', time() - $this->reports->screenshotDays() * 86400)) as $row) {
            $this->screenshots->remove($row['screenshot_path']);
            $this->reports->screenshotDeleted((int) $row['id'], $now);
            ++$count;
        }

        return $count;
    }

    public function screenshotDays(): int
    {
        return $this->reports->screenshotDays();
    }

    /** Durée de conservation des captures, de 1 à 3 650 jours (provisoire : fixée par le propriétaire avec le juriste) */
    public function setScreenshotDays(mixed $days, ?string $by): void
    {
        if (!is_int($days) && !(is_string($days) && ctype_digit($days)) || (int) $days < 1 || (int) $days > 3650) {
            throw new GestionValidationException(['screenshotDays' => 'INVALID_VALUE']);
        }
        $this->reports->setScreenshotDays((int) $days, $by);
    }

    /** @param list<string> $roles */
    public static function kind(array $roles): ?string
    {
        foreach (self::KINDS as $role => $kind) {
            if (in_array($role, $roles, true)) {
                return $kind;
            }
        }

        return null;
    }

    private function site(): string
    {
        return $this->tenants->getCurrentTenantCode() ?? throw new GestionConflictException('SITE_REQUIRED', 'Site inconnu.', 404);
    }

    private function userHash(int $userId): string
    {
        return substr(hash_hmac('sha256', 'user:' . $userId, $this->secret), 0, 16);
    }

    private static function date(?string $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /** Ce que lit le signaleur : jamais la note interne, jamais la capture ni sa présence */
    private static function reporterView(array $row): array
    {
        return ['id' => (int) $row['id'], 'app' => $row['app'], 'appVersion' => $row['app_version'], 'screen' => $row['screen'], 'description' => $row['description'],
            'status' => $row['status'], 'answer' => $row['answer'], 'createdAt' => self::date($row['created_at']), 'answeredAt' => self::date($row['answered_at'])];
    }

    /** Ce que lit le super administrateur ; la capture n'y est qu'un booléen, servie par sa propre route */
    private static function platformView(array $row): array
    {
        return self::reporterView($row) + ['tenantCode' => $row['tenant_code'], 'context' => $row['context'] !== null ? json_decode((string) $row['context'], true) : null,
            'reporterKind' => $row['reporter_kind'], 'reporterHash' => $row['reporter_hash'], 'supportTicketId' => $row['support_ticket_id'] !== null ? (int) $row['support_ticket_id'] : null,
            'incidentId' => $row['incident_id'] !== null ? (int) $row['incident_id'] : null, 'note' => $row['note'], 'screenshot' => $row['screenshot_path'] !== null,
            'screenshotDeletedAt' => self::date($row['screenshot_deleted_at']), 'updatedAt' => self::date($row['updated_at']), 'statusChangedBy' => $row['status_changed_by']];
    }
}
