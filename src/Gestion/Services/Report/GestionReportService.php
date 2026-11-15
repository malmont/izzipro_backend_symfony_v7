<?php

namespace App\Gestion\Services\Report;

use App\Gestion\Entity\GestionConversationMessage;
use App\Gestion\Message\GenerateGestionReportMessage;
use App\Gestion\Services\Console\ConsoleService;
use App\Gestion\Services\Document\GestionDocumentService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lot DOC-1 (15/11/2026, `docs/rapports-gestion.md`) : rapports PDF de la gestion. Un rapport se demande (il est aussitôt « en
 * préparation »), se fabrique en tâche de fond (file `pdf`), puis se lit, se télécharge, se liste et s'efface.
 *
 * Genre de ce lot : `console.answer`, une réponse de la console IA telle qu'elle a été lue. Le serveur reprend les blocs gardés :
 * rien n'est recalculé, AUCUN modèle n'est appelé, aucune unité n'est décomptée. Lecture seule : le lot n'écrit que la liste de ses
 * propres rapports. Garde-fous : `DAILY_LIMIT` rapports par jour du magasin et par site, `MAX_PAGES` pages, `TIMEOUT_SECONDS`
 * secondes, conservation `RETENTION_DAYS` jours. La même réponse demandée de nouveau rend le fichier déjà prêt (`reused`).
 */
class GestionReportService
{
    public const KIND_CONSOLE_ANSWER = 'console.answer';
    /** Genres que le contrat connaît ; ceux de ce lot sont dans `KINDS` */
    public const KINDS = [self::KIND_CONSOLE_ANSWER];
    public const DAILY_LIMIT = 30;
    public const MAX_PAGES = 40;
    public const TIMEOUT_SECONDS = 120;
    /** Un rapport resté « en préparation » plus longtemps est tenu pour interrompu. Large : la file `pdf` est partagée avec la mise en page des livres */
    public const STALE_SECONDS = 900;
    public const RETENTION_DAYS = 90;
    public const PER_PAGE = 20;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly GestionReportStore $store,
        private readonly ReportRenderer $renderer,
        private readonly GestionDocumentService $documents,
        private readonly TenantCurrencyProvider $currency,
        private readonly StoreClock $clock,
        private readonly MessageBusInterface $bus,
        private readonly ContentAuditRecorder $audit,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * POST /api/gestion/rapports.
     *
     * @param array<string, mixed> $body { kind, messageId, locale? }
     * @return array{0: array<string, mixed>, 1: int} le rapport et le statut HTTP (202 en préparation, 200 déjà prêt)
     */
    public function request(array $body, string $defaultLocale, ?string $by, bool $platformOwner): array
    {
        $fields = [];
        $kind = $body['kind'] ?? null;
        if (!is_string($kind) || $kind === '') {
            $fields['kind'] = 'REQUIRED';
        } elseif (!in_array($kind, self::KINDS, true)) {
            $fields['kind'] = 'INVALID_VALUE';
        }
        $messageId = $body['messageId'] ?? null;
        if ($kind === self::KIND_CONSOLE_ANSWER) {
            if ($messageId === null) {
                $fields['messageId'] = 'REQUIRED';
            } elseif (!is_int($messageId) || $messageId <= 0) {
                $fields['messageId'] = 'INVALID_ID';
            }
        }
        $locale = $body['locale'] ?? $defaultLocale;
        if (!in_array($locale, ['fr', 'en'], true)) {
            $fields['locale'] = 'INVALID_VALUE';
        }
        if (array_key_exists('withAdvice', $body)) {
            $fields['withAdvice'] = is_bool($body['withAdvice']) ? 'NOT_ALLOWED' : 'MUST_BE_BOOLEAN';
        }
        if ($fields !== []) {
            throw new GestionValidationException($fields, 'Demande de rapport invalide');
        }
        $this->documents->assertAiIncluded($platformOwner); // 403 : le rapport d'une réponse de la console demande le palier d'IA
        [$answer, $question] = $this->answer((int) $messageId);
        if ($answer['state'] === ConsoleService::STATE_PENDING) {
            throw new GestionConflictException('CONSOLE_ANSWER_PENDING', 'Cette réponse est encore en préparation.', 409, ['messageId' => (int) $messageId]);
        }
        if ($answer['state'] !== ConsoleService::STATE_DONE) {
            throw new GestionConflictException('CONSOLE_ANSWER_FAILED', 'Cette réponse n\'a pas abouti : il n\'y a rien à mettre en PDF.', 409, ['messageId' => (int) $messageId]);
        }
        $this->store->failStale(self::STALE_SECONDS, 'REPORT_TIMEOUT');
        // la même réponse, dans la même langue, avec le même en-tête du site : le même rapport
        $fingerprint = hash('sha256', implode('|', [$kind, (int) $messageId, $locale, hash('sha256', (string) json_encode([$answer['blocks'], $answer['analyses']])), $this->renderer->siteKey()]));
        $existing = $this->store->ready($fingerprint);
        if ($existing !== null && is_file($this->path($existing))) {
            return [$this->view($existing, $locale, true), 200];
        }
        if (($pending = $this->store->pending($fingerprint)) !== null) {
            return [$this->view($pending, $locale, true), 202];
        }
        [$dayStart, $dayEnd] = $this->clock->range(null, null);
        if ($this->store->createdBetween($dayStart, $dayEnd) >= self::DAILY_LIMIT) {
            throw new GestionConflictException('REPORT_DAILY_LIMIT', sprintf('%d rapports par jour au plus : reprenez demain.', self::DAILY_LIMIT), 429, ['limit' => self::DAILY_LIMIT, 'resetAt' => $dayEnd->format(\DateTimeInterface::ATOM)]);
        }
        $title = mb_substr($question !== '' ? $question : $this->translator->trans('reports.answer_title', [], 'gestion', $locale), 0, 120);
        try {
            $id = $this->store->create($kind, ['messageId' => (int) $messageId], $title, $locale, $fingerprint, $by, new \DateTimeImmutable('+' . self::RETENTION_DAYS . ' days', new \DateTimeZone('UTC')));
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // la même demande au même instant : la base n'en garde qu'une en préparation
            $pending = $this->store->pending($fingerprint) ?? throw new GestionConflictException('REPORT_NOT_READY', 'Ce rapport est déjà en préparation.', 409);

            return [$this->view($pending, $locale, true), 202];
        }
        $this->audit->record('gestion_report', $id, 'request', [], ['kind' => $kind, 'messageId' => (int) $messageId, 'locale' => $locale, 'by' => $by], ['state']);
        try {
            $this->bus->dispatch(new GenerateGestionReportMessage($id, (string) $this->tenants->getCurrentTenantCode()));
        } catch (\Throwable $e) {
            $this->logger->error('Rapports de la gestion : rapport ' . $id . ' non mis en file (' . $e::class . ').');
            $this->store->failed($id, 'REPORT_FAILED');
        }

        return [$this->view($this->report($id), $locale, false), 202];
    }

    /** Fabrique le fichier d'un rapport en préparation (worker). Ne lève jamais : un échec est noté sur le rapport. */
    public function generate(int $id): void
    {
        $started = microtime(true);
        try {
            $report = $this->store->find($id);
            if ($report === null || $report['state'] !== GestionReportStore::PENDING) {
                return;
            }
            [$answer, $question] = $this->answer((int) ($report['params']['messageId'] ?? 0));
            $document = $this->renderer->document($answer, $question, (string) $report['locale'], $this->currency->code(true)); // relue : un worker vit longtemps
            $pdf = $this->renderer->pdf($document);
            if ($pdf['pages'] > self::MAX_PAGES) {
                $this->store->failed($id, 'REPORT_TOO_LONG', $pdf['pages']);

                return;
            }
            if (microtime(true) - $started > self::TIMEOUT_SECONDS) {
                $this->store->failed($id, 'REPORT_TIMEOUT', $pdf['pages']);

                return;
            }
            $name = sprintf('rapport-%d-%s.pdf', $id, bin2hex(random_bytes(8)));
            (new Filesystem())->mkdir($this->directory(), 0750);
            if (file_put_contents($this->directory() . '/' . $name, $pdf['content']) === false) {
                throw new \RuntimeException('fichier du rapport non écrit');
            }
            if (!$this->store->done($id, $name, strlen($pdf['content']), $pdf['pages'])) {
                @unlink($this->directory() . '/' . $name); // le rapport a quitté « en préparation » entre-temps (délai, effacement)
            }
        } catch (\Throwable $e) {
            $this->logger->error('Rapports de la gestion : rapport ' . $id . ' en échec (' . $e::class . ').', ['exception' => $e]);
            try {
                $this->store->failed($id, 'REPORT_FAILED');
            } catch (\Throwable) {
                // la base ne répond plus : le rapport restera « en préparation », puis sera tenu pour interrompu
            }
        }
    }

    /** GET /api/gestion/rapports/{id} */
    public function show(int $id, string $locale): array
    {
        $this->store->failStale(self::STALE_SECONDS, 'REPORT_TIMEOUT');

        return $this->view($this->report($id), $locale, false);
    }

    /**
     * GET /api/gestion/rapports?page=&kind=
     *
     * @return array{reports: list<array<string, mixed>>, page: int, pages: int, total: int}
     */
    public function list(int $page, ?string $kind, string $locale): array
    {
        $this->purgeExpired();
        $this->store->failStale(self::STALE_SECONDS, 'REPORT_TIMEOUT');
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new GestionValidationException(['kind' => 'INVALID_VALUE'], 'Genre de rapport inconnu');
        }
        $page = max(1, $page);
        [$rows, $total] = $this->store->page($page, self::PER_PAGE, $kind);

        return ['reports' => array_map(fn (array $r): array => $this->view($r, $locale, false), $rows), 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PER_PAGE)), 'total' => $total];
    }

    /** @return array{path: string, filename: string} le fichier d'un rapport prêt */
    public function file(int $id): array
    {
        $report = $this->report($id);
        if ($report['state'] === GestionReportStore::PENDING) {
            throw new GestionConflictException('REPORT_NOT_READY', 'Ce rapport est encore en préparation.', 409, ['reportId' => $id]);
        }
        if ($report['state'] !== GestionReportStore::DONE || !is_file($this->path($report))) {
            throw new GestionConflictException('REPORT_FAILED_NO_FILE', 'Ce rapport n\'a pas de fichier.', 409, ['reportId' => $id]);
        }

        return ['path' => $this->path($report), 'filename' => $this->filename($report)];
    }

    /** DELETE /api/gestion/rapports/{id} */
    public function delete(int $id, ?string $by): void
    {
        $report = $this->report($id);
        if ($report['state'] === GestionReportStore::PENDING) {
            throw new GestionConflictException('REPORT_NOT_READY', 'Ce rapport est encore en préparation.', 409, ['reportId' => $id]);
        }
        if ($report['file_name'] !== null) {
            @unlink($this->path($report));
        }
        $this->store->markDeleted($id); // la ligne reste jusqu'à la fin de sa conservation : effacer ne rend pas une place au plafond du jour
        $this->audit->record('gestion_report', $id, 'delete', ['kind' => $report['kind'], 'title' => $report['title']], ['by' => $by], ['state']);
    }

    /** Retire les rapports dont le délai de conservation est passé, avec leur fichier ; rend leur nombre */
    public function purgeExpired(): int
    {
        $removed = 0;
        foreach ($this->store->expired() as $report) {
            if ($report['file_name'] !== null) {
                @unlink($this->path($report));
            }
            $this->store->delete($report['id']);
            ++$removed;
        }

        return $removed;
    }

    /**
     * La réponse de la console à mettre en page, et sa question.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function answer(int $messageId): array
    {
        $em = $this->emProvider->getEntityManager();
        $message = $messageId > 0 ? $em->getRepository(GestionConversationMessage::class)->find($messageId) : null;
        if ($message === null || $message->getRole() !== GestionConversationMessage::ROLE_ASSISTANT || $message->getConversation()?->getKind() !== ConsoleService::KIND) {
            throw new GestionConflictException('CONSOLE_MESSAGE_NOT_FOUND', 'Réponse de la console introuvable.', 404, ['messageId' => $messageId]);
        }
        $em->refresh($message); // une réponse est close en SQL : l'objet en mémoire peut être encore « en préparation »
        $answer = ConsoleService::message($message);
        $question = '';
        if (is_int($answer['replyTo'] ?? null)) {
            $asked = $em->getRepository(GestionConversationMessage::class)->find($answer['replyTo']);
            $question = $asked !== null ? (string) ($asked->getContent()['text'] ?? '') : '';
        }

        return [$answer, $question];
    }

    /** @return array<string, mixed> */
    private function report(int $id): array
    {
        return $this->store->readable($id) ?? throw new GestionConflictException('REPORT_NOT_FOUND', 'Rapport introuvable.', 404, ['reportId' => $id]);
    }

    /** @param array<string, mixed> $r */
    private function view(array $r, string $locale, bool $reused): array
    {
        $iso = static fn (?string $at): ?string => $at === null ? null : (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $done = $r['state'] === GestionReportStore::DONE;
        $error = null;
        if ($r['state'] === GestionReportStore::FAILED) {
            $code = in_array($r['error_code'], ['REPORT_TOO_LONG', 'REPORT_TIMEOUT', 'REPORT_FAILED'], true) ? $r['error_code'] : 'REPORT_FAILED';
            $error = ['code' => $code, 'message' => $this->translator->trans('reports.error.' . $code, ['%max%' => self::MAX_PAGES], 'gestion', $locale === 'en' ? 'en' : 'fr')];
        }

        return [
            'id' => $r['id'], 'kind' => $r['kind'], 'state' => $r['state'], 'title' => $r['title'], 'locale' => $r['locale'], 'params' => $r['params'] ?: new \stdClass(),
            'createdAt' => $iso($r['created_at']), 'readyAt' => $iso($r['ready_at']), 'expiresAt' => $iso($r['expires_at']), 'pages' => $r['pages'], 'size' => $r['size'],
            'download' => $done ? ['url' => '/api/gestion/rapports/' . $r['id'] . '/fichier', 'filename' => $this->filename($r), 'contentType' => 'application/pdf'] : null,
            'advice' => null, 'error' => $error, 'reused' => $reused, 'unitsCharged' => (int) $r['units_charged'], 'credits' => null,
        ];
    }

    /** @param array<string, mixed> $r */
    private function filename(array $r): string
    {
        $slug = (new AsciiSlugger('fr'))->slug(mb_substr((string) $r['title'], 0, 60))->lower()->toString();

        return sprintf('rapport-%s-%d.pdf', $slug !== '' ? $slug : 'gestion', $r['id']);
    }

    /** @param array<string, mixed> $r */
    private function path(array $r): string
    {
        return $this->directory() . '/' . basename((string) $r['file_name']);
    }

    private function directory(): string
    {
        $code = preg_replace('/[^a-z0-9_-]/i', '', (string) $this->tenants->getCurrentTenantCode()) ?: 'site';

        return $this->projectDir . '/var/storage/private_media/gestion-rapports/' . $code;
    }
}
