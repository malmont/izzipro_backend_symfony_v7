<?php

namespace App\Gestion\Services\Report;

use App\Entity\AiUsage;
use App\Gestion\Entity\GestionConversationMessage;
use App\Gestion\Message\GenerateGestionReportMessage;
use App\Gestion\Services\Console\ConsoleResponder;
use App\Gestion\Services\Console\ConsoleService;
use App\Gestion\Services\Document\GestionDocumentService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingAiService\LandingAiQuotaService;
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
 * Genre `console.answer` : une réponse de la console IA telle qu'elle a été lue. Le serveur reprend les blocs gardés : rien n'est
 * recalculé, AUCUN modèle n'est appelé, aucune unité n'est décomptée. Lecture seule : le lot n'écrit que la liste de ses propres
 * rapports. Garde-fous : `DAILY_LIMIT` rapports par jour du magasin et par site, `MAX_PAGES` pages, `TIMEOUT_SECONDS` secondes,
 * conservation `RETENTION_DAYS` jours. La même réponse demandée de nouveau rend le fichier déjà prêt (`reused`).
 *
 * Lot DOC-2 (15/11/2026) : genres `period` (bilan d'une période) et `year` (bilan de l'année ou de l'exercice). Le serveur lance
 * lui-même les analyses nommées de la console (`ReportReview`) À LA DEMANDE et garde leur résultat avec le rapport : le worker met en
 * page ces chiffres-là, sans rien recalculer. Sans `withAdvice` : aucun modèle, 0 unité. Avec `withAdvice: true`, le bilan se termine
 * par un « Avis de la console » : une question posée à la console pour le gérant (1 unité, réservée à la demande : palier, crédits et
 * plafond du jour refusent AVANT toute création), rédigée par le worker sur les analyses du bilan, imposées par le serveur. Si l'avis
 * ne peut pas être rédigé, le bilan sort sans lui (il le dit), l'unité est rendue, et le rapport prend une empreinte à lui : aucune
 * demande suivante ne retombe sur ce fichier.
 */
class GestionReportService
{
    public const KIND_CONSOLE_ANSWER = 'console.answer';
    public const KIND_PERIOD = 'period';
    public const KIND_YEAR = 'year';
    public const KINDS = [self::KIND_CONSOLE_ANSWER, self::KIND_PERIOD, self::KIND_YEAR];
    /** Issues d'un avis qui n'a pas pu être rédigé, telles que l'application les lit */
    public const ADVICE_CODES = ['AI_UNAVAILABLE', 'CONSOLE_TIMEOUT'];
    public const DAILY_LIMIT = 30;
    public const MAX_PAGES = 40;
    public const TIMEOUT_SECONDS = 120;
    /** Un rapport resté « en préparation » plus longtemps est tenu pour interrompu. Large : la file `pdf` est partagée avec la mise en page des livres */
    public const STALE_SECONDS = 900;
    public const RETENTION_DAYS = 90;
    public const PER_PAGE = 20;

    /** Le solde d'unités du site, lu une fois par appel (une liste porte plusieurs rapports avec avis) */
    private ?array $credits = null;

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
        private readonly ReportReview $reviews,
        private readonly ConsoleService $console,
        private readonly ConsoleResponder $responder,
        private readonly LandingAiQuotaService $quota,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * POST /api/gestion/rapports.
     *
     * @param array<string, mixed> $body { kind: console.answer + messageId | period + periodId + withAdvice? | year + year? + withAdvice?, locale? }
     * @return array{0: array<string, mixed>, 1: int} le rapport et le statut HTTP (202 en préparation, 200 déjà prêt)
     */
    public function request(array $body, string $defaultLocale, ?string $by, bool $platformOwner): array
    {
        $this->credits = null;
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
        if ($kind === self::KIND_PERIOD) {
            if (($body['periodId'] ?? null) === null) {
                $fields['periodId'] = 'REQUIRED';
            } elseif (!is_int($body['periodId']) || $body['periodId'] <= 0) {
                $fields['periodId'] = 'INVALID_ID';
            }
        }
        if ($kind === self::KIND_YEAR && ($body['year'] ?? null) !== null && !is_int($body['year'])) {
            $fields['year'] = 'OUT_OF_RANGE';
        }
        $locale = $body['locale'] ?? $defaultLocale;
        if (!in_array($locale, ['fr', 'en'], true)) {
            $fields['locale'] = 'INVALID_VALUE';
        }
        if (array_key_exists('withAdvice', $body)) {
            if (!is_bool($body['withAdvice'])) {
                $fields['withAdvice'] = 'MUST_BE_BOOLEAN';
            } elseif ($kind === self::KIND_CONSOLE_ANSWER && $body['withAdvice']) {
                $fields['withAdvice'] = 'NOT_ALLOWED'; // une réponse de la console est déjà un avis ; `false` ne demande rien : admis
            }
        }
        if ($fields !== []) {
            throw new GestionValidationException($fields, 'Demande de rapport invalide');
        }
        if ($kind !== self::KIND_CONSOLE_ANSWER) {
            return $this->requestReview($kind, $body, $locale, $by, $platformOwner);
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
        $units = 0; // l'unité de l'avis de la console, s'il a été rédigé : elle reste notée même si le fichier échoue ensuite
        try {
            $report = $this->store->find($id);
            if ($report === null || $report['state'] !== GestionReportStore::PENDING) {
                return;
            }
            $outcome = null;
            $fingerprint = null;
            $currency = $this->currency->code(true); // relue : un worker vit longtemps
            if ($report['kind'] === self::KIND_CONSOLE_ANSWER) {
                [$answer, $question] = $this->answer((int) ($report['params']['messageId'] ?? 0));
                $document = $this->renderer->document($answer, $question, (string) $report['locale'], $currency);
            } else {
                // un bilan : les chiffres calculés à la demande, tels quels
                $content = is_array($report['content']) ? $report['content'] : throw new \RuntimeException('bilan sans contenu');
                $advice = null;
                if (is_int($report['advice']['messageId'] ?? null)) {
                    $advice = $this->advice($report['advice']['messageId'], (array) ($content['selected'] ?? []));
                    $units = $advice['units'];
                    $started = microtime(true); // le délai de fabrication mesure la mise en page, pas la rédaction de l'avis (bornée par la console)
                    $outcome = $report['advice'] + ($advice['included'] ? ['included' => true] : ['included' => false, 'code' => $advice['code']]);
                    // avis manqué : ce fichier dit que l'avis manque. Ni la demande AVEC avis (elle le redemande), ni la demande SANS avis
                    // (elle veut un bilan propre) ne doivent retomber dessus : il prend une empreinte à lui
                    $fingerprint = $advice['included'] ? null : hash('sha256', (string) $report['fingerprint'] . '|missing|' . $id);
                }
                $document = $this->renderer->review($content, $advice, (string) $report['locale'], $currency);
            }
            $pdf = $this->renderer->pdf($document);
            if ($pdf['pages'] > self::MAX_PAGES) {
                $this->store->failed($id, 'REPORT_TOO_LONG', $pdf['pages'], $units);

                return;
            }
            if (microtime(true) - $started > self::TIMEOUT_SECONDS) {
                $this->store->failed($id, 'REPORT_TIMEOUT', $pdf['pages'], $units);

                return;
            }
            $name = sprintf('rapport-%d-%s.pdf', $id, bin2hex(random_bytes(8)));
            (new Filesystem())->mkdir($this->directory(), 0750);
            if (file_put_contents($this->directory() . '/' . $name, $pdf['content']) === false) {
                throw new \RuntimeException('fichier du rapport non écrit');
            }
            if (!$this->store->done($id, $name, strlen($pdf['content']), $pdf['pages'], $outcome, $units, $fingerprint)) {
                @unlink($this->directory() . '/' . $name); // le rapport a quitté « en préparation » entre-temps (délai, effacement)
            }
        } catch (\Throwable $e) {
            $this->logger->error('Rapports de la gestion : rapport ' . $id . ' en échec (' . $e::class . ').', ['exception' => $e]);
            try {
                $this->store->failed($id, 'REPORT_FAILED', null, $units);
            } catch (\Throwable) {
                // la base ne répond plus : le rapport restera « en préparation », puis sera tenu pour interrompu
            }
        }
    }

    /**
     * Demande d'un bilan de période ou d'année (lot DOC-2). Les chiffres sont calculés ICI, par les analyses nommées : la même période
     * sans changement de ses chiffres rend le fichier déjà prêt.
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function requestReview(string $kind, array $body, string $locale, ?string $by, bool $platformOwner): array
    {
        $withAdvice = ($body['withAdvice'] ?? false) === true;
        if ($withAdvice) {
            $this->documents->assertAiIncluded($platformOwner); // 403 : l'avis demande le palier d'IA, dit avant tout calcul
        }
        if ($kind === self::KIND_PERIOD) {
            $content = $this->reviews->period((int) $body['periodId'], $locale) ?? throw new GestionConflictException('PERIOD_NOT_FOUND', 'Période introuvable.', 404, ['periodId' => (int) $body['periodId']]);
        } else {
            $content = $this->reviews->year($body['year'] ?? null, $locale) ?? throw new GestionValidationException(['year' => 'OUT_OF_RANGE'], 'Demande de rapport invalide');
        }
        $this->store->failStale(self::STALE_SECONDS, 'REPORT_TIMEOUT');
        // le même bilan : mêmes chiffres, même texte, même langue, même en-tête du site ; l'avis fait un autre rapport
        $plain = hash('sha256', implode('|', [$kind, (string) json_encode($content['resolved']), $locale, ReportReview::key($content), $this->renderer->siteKey()]));
        $fingerprint = $withAdvice ? hash('sha256', $plain . '|advice') : $plain;
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
        $advice = null;
        if ($withAdvice) {
            // la question est posée à la console au nom du gérant : palier (403), crédits (402) et plafond du jour (429) refusent ici, avant
            // toute création ; 1 unité est réservée, que le worker confirme (avis rédigé) ou rend (avis impossible)
            $asked = $this->console->ask($this->translator->trans('reports.advice_question_' . $kind, [], 'gestion', $locale), null, null, $locale, $by, $platformOwner, null, false);
            $advice = ['messageId' => (int) $asked['messageId'], 'conversationId' => (int) $asked['conversationId']];
        }
        $params = $content['resolved'] + ['withAdvice' => $withAdvice];
        try {
            $id = $this->store->create($kind, $params, mb_substr((string) $content['title'], 0, 120), $locale, $fingerprint, $by, new \DateTimeImmutable('+' . self::RETENTION_DAYS . ' days', new \DateTimeZone('UTC')), $content, $advice);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // la même demande au même instant : la base n'en garde qu'une en préparation ; la question posée pour rien est retirée, son unité rendue
            $this->dropAdvice($advice);
            $pending = $this->store->pending($fingerprint) ?? throw new GestionConflictException('REPORT_NOT_READY', 'Ce rapport est déjà en préparation.', 409);

            return [$this->view($pending, $locale, true), 202];
        } catch (\Throwable $e) {
            $this->dropAdvice($advice); // aucun rapport : la question ne reste pas dans l'historique, son unité est rendue
            throw $e;
        }
        $this->audit->record('gestion_report', $id, 'request', [], ['kind' => $kind, 'locale' => $locale, 'by' => $by] + $params, ['state']);
        try {
            $this->bus->dispatch(new GenerateGestionReportMessage($id, (string) $this->tenants->getCurrentTenantCode()));
        } catch (\Throwable $e) {
            $this->logger->error('Rapports de la gestion : rapport ' . $id . ' non mis en file (' . $e::class . ').');
            $this->store->failed($id, 'REPORT_FAILED');
            $this->dropAdvice($advice);
        }

        return [$this->view($this->report($id), $locale, false), 202];
    }

    /**
     * Fait rédiger l'avis de la console joint à un bilan (worker) : les analyses sont celles du bilan, imposées par le serveur ; le modèle
     * ne choisit rien, il rédige. Ne lève jamais. Un avis n'est « rédigé » que si la console a rendu un texte du modèle : une réponse de
     * repli (écrite par le serveur), un échec ou un délai dépassé valent « non rédigé », et l'unité est alors rendue par la console.
     *
     * @param array<int, mixed> $selected
     * @return array{included: bool, code: ?string, blocks: list<array<string, mixed>>, answeredAt: ?string, units: int}
     */
    private function advice(int $messageId, array $selected): array
    {
        $none = fn (string $code): array => ['included' => false, 'code' => $code, 'blocks' => [], 'answeredAt' => null, 'units' => $this->unitsOf($messageId)];
        try {
            // le délai de la console vaut ici aussi : une question restée trop longtemps en attente (file occupée) est close, son unité rendue
            $this->console->releaseStale();
            $forced = array_values(array_filter($selected, static fn (mixed $a): bool => is_array($a) && is_string($a['key'] ?? null) && is_array($a['params'] ?? null)));
            $this->responder->answer($messageId, $forced);
            $em = $this->emProvider->getEntityManager();
            $message = $em->find(GestionConversationMessage::class, $messageId);
            if ($message === null) {
                return $none('AI_UNAVAILABLE');
            }
            $em->refresh($message); // la réponse est close en SQL
            $raw = $message->getContent();
            $answer = ConsoleService::message($message);
            $texts = array_filter((array) $answer['blocks'], static fn (mixed $b): bool => is_array($b) && ($b['type'] ?? null) === 'text' && trim((string) ($b['text'] ?? '')) !== '');
            // un avis est un texte du modèle sur des analyses : ni une réponse de repli, ni une demande de précision (aucune analyse)
            if ($answer['state'] !== ConsoleService::STATE_DONE || ($raw['fallback'] ?? false) === true || $texts === [] || $answer['analyses'] === []) {
                $code = is_array($answer['error']) ? ($answer['error']['code'] ?? null) : null;

                return $none(in_array($code, self::ADVICE_CODES, true) ? $code : 'AI_UNAVAILABLE');
            }

            return ['included' => true, 'code' => null, 'blocks' => array_values((array) $answer['blocks']), 'answeredAt' => $answer['answeredAt'], 'units' => $this->unitsOf($messageId)];
        } catch (\Throwable $e) {
            $this->logger->error('Rapports de la gestion : avis de la console non rédigé (' . $e::class . ').', ['exception' => $e]);

            return $none('AI_UNAVAILABLE');
        }
    }

    /** L'unité réellement décomptée pour la question d'un avis, relue en base (0 si elle a été rendue, ou pour le propriétaire de la plateforme). Ne lève jamais. */
    private function unitsOf(int $messageId): int
    {
        try {
            $units = $this->emProvider->getEntityManager()->getConnection()->fetchOne(
                "SELECT u.credits FROM ai_usage u JOIN gestion_conversation_message m ON (m.content::jsonb ->> 'usageId')::int = u.id WHERE m.id = ? AND u.status = ?", [$messageId, AiUsage::STATUS_SUCCESS]);

            return $units === false ? 0 : (int) $units;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Retire la question posée à la console pour un bilan qui n'aura pas lieu (demande en double, mise en file impossible) : la réponse en
     * attente est close en échec, ce qui rend son unité, puis l'échange est retiré de l'historique.
     *
     * @param array<string, mixed>|null $advice
     */
    private function dropAdvice(?array $advice): void
    {
        if ($advice === null) {
            return;
        }
        try {
            $em = $this->emProvider->getEntityManager();
            $message = $em->find(GestionConversationMessage::class, (int) $advice['messageId']);
            if ($message !== null) {
                $this->console->finish((int) $advice['messageId'], $message->getContent(), ConsoleService::STATE_FAILED, ['error' => ['code' => 'AI_UNAVAILABLE', 'message' => 'Bilan non fabriqué.']], false);
            }
            $em->getConnection()->executeStatement('DELETE FROM gestion_conversation WHERE id = ? AND kind = ?', [(int) $advice['conversationId'], ConsoleService::KIND]);
        } catch (\Throwable $e) {
            $this->logger->error('Rapports de la gestion : question de l\'avis non retirée (' . $e::class . ').'); // l'unité sera rendue au délai de la console
        }
    }

    /** GET /api/gestion/rapports/{id} */
    public function show(int $id, string $locale): array
    {
        $this->credits = null;
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
        $this->credits = null;
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
        // l'avis de la console (bilans) : dit seulement quand le rapport est prêt ; `credits` : le solde du site, pour une demande avec avis
        $advice = null;
        if ($done && is_array($r['advice'] ?? null) && array_key_exists('included', $r['advice'])) {
            $advice = $r['advice']['included'] === true ? ['included' => true] : ['included' => false, 'code' => in_array($r['advice']['code'] ?? null, self::ADVICE_CODES, true) ? $r['advice']['code'] : 'AI_UNAVAILABLE'];
        }
        $error = null;
        if ($r['state'] === GestionReportStore::FAILED) {
            $code = in_array($r['error_code'], ['REPORT_TOO_LONG', 'REPORT_TIMEOUT', 'REPORT_FAILED'], true) ? $r['error_code'] : 'REPORT_FAILED';
            $error = ['code' => $code, 'message' => $this->translator->trans('reports.error.' . $code, ['%max%' => self::MAX_PAGES], 'gestion', $locale === 'en' ? 'en' : 'fr')];
        }

        return [
            'id' => $r['id'], 'kind' => $r['kind'], 'state' => $r['state'], 'title' => $r['title'], 'locale' => $r['locale'], 'params' => $r['params'] ?: new \stdClass(),
            'createdAt' => $iso($r['created_at']), 'readyAt' => $iso($r['ready_at']), 'expiresAt' => $iso($r['expires_at']), 'pages' => $r['pages'], 'size' => $r['size'],
            'download' => $done ? ['url' => '/api/gestion/rapports/' . $r['id'] . '/fichier', 'filename' => $this->filename($r), 'contentType' => 'application/pdf'] : null,
            'advice' => $advice, 'error' => $error, 'reused' => $reused, 'unitsCharged' => (int) $r['units_charged'], 'credits' => ($r['params']['withAdvice'] ?? false) === true ? ($this->credits ??= $this->quota->credits()) : null,
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
