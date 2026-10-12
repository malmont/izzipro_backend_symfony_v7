<?php

namespace App\UseCase\SiteContentUseCase;

use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\SiteContentService\SiteContentCopier;
use App\Services\SiteContentService\SiteContentException;
use App\Services\SiteContentService\SiteContentSpec;
use App\Services\SiteContentService\SiteDemoContentService;
use App\Services\TenantCacheService;

/**
 * Contenu d'exemple du site courant (12/10/2026) : copie depuis le site de référence, liste et suppression des fiches
 * encore d'exemple. Écritures journalisées (resource « site-content », actions seed et demo-delete) : l'une s'annule
 * par l'autre (DELETE /api/site-content/demo retire une copie ; seed recopie), pas par un retour en arrière du journal.
 * Tout le cache de lecture du site est vidé après une écriture.
 */
final class ManageSiteContentUseCase
{
    public function __construct(
        private readonly SiteContentCopier $copier,
        private readonly SiteDemoContentService $demo,
        private readonly ContentAuditRecorder $audit,
        private readonly TenantCacheService $cache
    ) {
    }

    /**
     * { scope: ["landing", "boutique"], mode: "empty" | "add" }
     *
     * @return array{created: array<string, int>, ids: array<string, array<int, int>>}
     * @throws SiteContentException 400, 409 ou 422
     */
    public function seed(mixed $body): array
    {
        if (!is_object($body)) {
            throw new SiteContentException(400, 'Objet JSON attendu : { "scope": ["landing", "boutique"], "mode": "empty" | "add" }.');
        }
        $scope = $body->scope ?? null;
        if (!is_array($scope) || $scope === [] || array_diff($scope, SiteContentSpec::SCOPES) !== []) {
            throw new SiteContentException(422, 'scope : liste non vide parmi ' . implode(', ', SiteContentSpec::SCOPES), [['path' => 'scope', 'message' => 'valeurs attendues : ' . implode(', ', SiteContentSpec::SCOPES)]]);
        }
        $mode = $body->mode ?? null;
        if (!in_array($mode, SiteContentSpec::MODES, true)) {
            throw new SiteContentException(422, 'mode : ' . implode(' ou ', SiteContentSpec::MODES), [['path' => 'mode', 'message' => 'valeurs attendues : ' . implode(', ', SiteContentSpec::MODES)]]);
        }
        $scope = array_values(array_unique($scope));

        $result = $this->copier->seed($scope, $mode);
        if ($result['created'] !== []) {
            $this->audit->record('site-content', null, 'seed', null, ['scope' => $scope, 'mode' => $mode] + $result, array_keys($result['created']));
            $this->cache->invalidateTenant();
        }

        return ['created' => (object) $result['created'], 'ids' => (object) array_map(fn ($ids) => (object) $ids, $result['ids'])];
    }

    /** @return list<array{resource: string, id: int, label: ?string, fields: list<string>}> */
    public function list(string $locale): array
    {
        return $this->demo->list(self::locale($locale));
    }

    /**
     * @return array{deleted: array<string, int>, kept: list<array>}
     * @throws SiteContentException 409 (liste des produits utilisés)
     */
    public function delete(string $locale): array
    {
        $before = $this->demo->list(self::locale($locale));
        $result = $this->demo->delete(self::locale($locale));
        if ($result['deleted'] !== [] || $result['kept'] !== []) {
            $this->audit->record('site-content', null, 'demo-delete', $before, $result, array_keys($result['deleted']), $locale);
            $this->cache->invalidateTenant();
        }

        return ['deleted' => (object) $result['deleted'], 'kept' => $result['kept']];
    }

    private static function locale(string $locale): string
    {
        return in_array($locale, ['fr', 'en'], true) ? $locale : 'fr';
    }
}
