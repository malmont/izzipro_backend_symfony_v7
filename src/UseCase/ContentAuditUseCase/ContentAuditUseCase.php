<?php

namespace App\UseCase\ContentAuditUseCase;

use App\Dto\ContentAuditEntryDto;
use App\Entity\ContentAuditLog;
use App\Services\ContentAuditService\ContentAuditException;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\ContentAuditService\ContentAuditRestorer;

/**
 * Journal des écritures de l'éditeur des landing pages, propre au site : liste (GET /api/landingpage-audit), détail
 * avec les états avant / après (GET …/{id}) et retour à l'état d'avant (POST …/{id}/restore), lui-même journalisé.
 */
class ContentAuditUseCase
{
    public const DEFAULT_LIMIT = 30;
    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly ContentAuditRecorder $recorder,
        private readonly ContentAuditRestorer $restorer
    ) {
    }

    /** @throws ContentAuditException 400 */
    public function list(?string $resource, ?string $resourceId, ?string $page, ?string $limit): array
    {
        if ($resource !== null && !preg_match('/^[a-z-]{1,40}$/', $resource)) {
            throw new ContentAuditException(400, 'resource invalide.');
        }
        if ($resourceId !== null && !preg_match('/^\d{1,20}$/', $resourceId)) {
            throw new ContentAuditException(400, 'resourceId invalide.');
        }
        $pageNumber = self::integer($page, 1, 100000, 'page');
        $size = self::integer($limit ?? (string) self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, 'limit');
        [$entries, $total] = $this->recorder->repository()->search($resource, $resourceId, $pageNumber, $size);

        return ['items' => array_map(fn ($e) => ContentAuditEntryDto::fromEntity($e), $entries), 'total' => $total, 'page' => $pageNumber, 'limit' => $size];
    }

    /** @throws ContentAuditException 404 */
    public function get(int $id): ContentAuditEntryDto
    {
        return ContentAuditEntryDto::fromEntity($this->entry($id), true);
    }

    /**
     * @return array{entry: ContentAuditEntryDto, result: mixed} nouvelle entrée du journal, ressource rétablie
     * @throws ContentAuditException 404, 409 ou 422
     */
    public function restore(int $id, bool $force, string $host): array
    {
        $entry = $this->entry($id);
        $restored = $this->restorer->restore($entry, $force, $host);
        $log = $this->recorder->record($entry->getResource(), $restored['resourceId'], 'restore', $restored['before'], $restored['after'], $entry->getFields(), $entry->getLocale(), $id);

        return ['entry' => ContentAuditEntryDto::fromEntity($log), 'result' => $restored['result']];
    }

    private function entry(int $id): ContentAuditLog
    {
        return $this->recorder->repository()->find($id) ?? throw new ContentAuditException(404, 'Entrée du journal introuvable sur ce site.');
    }

    private static function integer(?string $value, int $min, int $max, string $name): int
    {
        if ($value === null || $value === '') {
            return $min;
        }
        if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
            throw new ContentAuditException(400, sprintf('%s invalide : entier de %d à %d attendu.', $name, $min, $max));
        }

        return (int) $value;
    }
}
