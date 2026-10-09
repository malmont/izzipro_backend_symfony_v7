<?php

namespace App\Services\ContentAuditService;

use App\Dto\LandingSiteModelSummaryDto;
use App\Dto\PresentationGroupOutputDto;
use App\Entity\ContentAuditLog;
use App\Entity\SharedMedia;
use App\Services\BoutiqueSettingsService\BoutiqueConfigurationValidator;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\EntrepriseService\EntrepriseService;
use App\Services\EntrepriseService\EntrepriseValidationException;
use App\Services\LandingContentService\LandingContentEditor;
use App\Services\LandingContentService\LandingContentSpec;
use App\Services\LandingContentService\PresentationGroupEditor;
use App\Services\LandingPageSettingsService\LandingPageSettingsService;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;
use App\Services\LandingSiteModelService\LandingSiteModelException;
use App\Services\LandingSiteModelService\LandingSiteModelService;
use App\Services\MediaUrlResolver;
use App\Services\SharedMedia\SharedMediaLibrary;
use App\Services\TenantCacheService;
use App\UseCase\LandingContentUseCase\LandingContentException;

/**
 * Retour à l'état d'avant d'une écriture du journal. Possible pour : modification d'un contenu de section, de la
 * fiche entreprise, des réglages publiés (landing page ou boutique) ou d'un modèle de site, suppression d'un modèle de
 * site (recréé, nouvel identifiant), renommage d'un média, ordre d'un groupe de présentations, et un retour en arrière
 * lui-même. Pas pour un téléversement, un ajout ou un retrait de présentation, ni la suppression d'un média (fichier
 * effacé).
 *
 * Sécurité : si la ressource a été modifiée depuis cette écriture (son état actuel n'est plus l'état « après »), le
 * retour est refusé (409) sauf demande explicite ($force), pour ne pas écraser sans le voir un travail plus récent.
 */
final class ContentAuditRestorer
{
    /** action => true (toujours), ou liste des ressources pour lesquelles l'action se rétablit */
    public const RESTORABLE = [
        'update' => true, 'restore' => true, 'rename' => true, 'reorder' => true,
        'delete' => ['landingpage-site-models', 'boutique-site-models'],
    ];

    public function __construct(
        private readonly LandingContentEditor $contentEditor,
        private readonly PresentationGroupEditor $groupEditor,
        private readonly EntrepriseService $entreprises,
        private readonly LandingPageSettingsService $settings,
        private readonly ReglableCompositionValidator $validator,
        private readonly BoutiqueSettingsService $boutiqueSettings,
        private readonly BoutiqueConfigurationValidator $boutiqueValidator,
        private readonly LandingSiteModelService $models,
        private readonly SharedMediaLibrary $media,
        private readonly MediaUrlResolver $urls,
        private readonly TenantCacheService $cache,
        private readonly TenantCurrencyProvider $currency,
        private readonly \App\Services\LandingContentService\LandingContentOutput $outputs,
        private readonly \App\Services\LandingContentService\BoutiqueCatalogEditor $catalog
    ) {
    }

    public static function restorable(ContentAuditLog $entry): bool
    {
        $rule = self::RESTORABLE[$entry->getAction()] ?? false;

        return ($rule === true || (is_array($rule) && in_array($entry->getResource(), $rule, true))) && $entry->getBefore() !== null;
    }

    /**
     * @return array{resourceId: int|string|null, before: mixed, after: mixed, result: mixed} état écrasé, état rétabli,
     *         ressource telle que sa lecture la renvoie (null pour les réglages : les relire)
     * @throws ContentAuditException 404, 409 ou 422
     */
    public function restore(ContentAuditLog $entry, bool $force, string $host): array
    {
        if (!self::restorable($entry)) {
            throw new ContentAuditException(409, 'Cette écriture ne peut pas être annulée (téléversement, ajout, retrait ou suppression de fichier).');
        }
        $before = json_decode((string) $entry->getBefore(), false);
        $after = json_decode((string) $entry->getAfter(), false);
        $resource = $entry->getResource();
        $id = $entry->getResourceId();
        $locale = $entry->getLocale() ?? 'fr';

        try {
            return match (true) {
                isset(LandingContentSpec::RESOURCES[$resource]) && $entry->getAction() !== 'reorder' => $this->content($resource, (int) $id, (array) $before, (array) $after, $locale, $force, $host),
                $resource === 'presentation-groups' => $this->order((int) $id, $before, $after, $locale, $force, $host),
                $resource === 'features' && $entry->getAction() === 'reorder' => $this->featureOrder($before, $after, $locale, $force, $host),
                $resource === 'entreprise' => $this->entreprise((int) $id, (array) $before, (array) $after, $locale, $force, $host),
                $resource === 'landingpage-settings' => $this->settings($entry->getBefore(), $entry->getAfter(), $force),
                $resource === 'boutique-settings' => $this->boutiqueSettings($entry->getBefore(), $entry->getAfter(), $force),
                str_ends_with($resource, '-site-models') => $this->siteModel($entry, $before, $force),
                $resource === 'media' => $this->mediaTitle((int) $id, $before, $after, $force, $host),
                default => throw new ContentAuditException(409, 'Ressource sans retour en arrière.'),
            };
        } catch (LandingContentException|LandingSiteModelException $e) {
            throw new ContentAuditException($e->getStatusCode(), $e->getMessage(), $e->errors);
        } catch (EntrepriseValidationException $e) {
            throw new ContentAuditException(422, $e->getMessage() . ' (texte d\'avant le filtrage HTML : à corriger avant de le rétablir)', $e->errors);
        }
    }

    private function content(string $resource, int $id, array $before, array $after, string $locale, bool $force, string $host): array
    {
        $entity = $this->contentEditor->find($resource, $id) ?? throw new ContentAuditException(404, 'Contenu supprimé depuis.');
        $current = $this->contentEditor->snapshot($resource, $entity, array_keys($before), $locale);
        $this->guard($current, $after, $force);
        $required = array_keys(array_filter(LandingContentSpec::RESOURCES[$resource]['fields'], fn ($f) => $f[3]));
        $values = array_filter($before, fn ($value, $field) => isset(LandingContentSpec::RESOURCES[$resource]['fields'][$field]) && ($value !== null || !in_array($field, $required, true)), ARRAY_FILTER_USE_BOTH);
        $this->contentEditor->apply($resource, $entity, $values, $locale);
        $this->cache->invalidateTags(array_map(fn ($tag) => sprintf($tag, $id), LandingContentSpec::RESOURCES[$resource]['tags']));

        return ['resourceId' => $id, 'before' => $current, 'after' => $values, 'result' => $this->outputs->output($resource, $entity, $locale, $host)];
    }

    private function order(int $id, mixed $before, mixed $after, string $locale, bool $force, string $host): array
    {
        $group = $this->groupEditor->group($id);
        $current = array_map(fn ($p) => (int) $p->getId(), $group->getOrderedPresentations());
        $this->guard($current, $after->order ?? null, $force);
        $this->groupEditor->reorder($group, (object) ['order' => $before->order ?? []]);
        $this->cache->invalidateTags(['presentations_all', 'presentation_groups_all', 'presentation_group_' . $id]);

        return ['resourceId' => $id, 'before' => ['order' => $current], 'after' => ['order' => $before->order ?? []],
            'result' => new PresentationGroupOutputDto($group, $this->urls->getSliderBaseUrl($host), $locale)];
    }

    /** Ordre des atouts de l'accueil (09/10/2026) */
    private function featureOrder(mixed $before, mixed $after, string $locale, bool $force, string $host): array
    {
        $current = $this->catalog->featureOrder();
        $this->guard($current, $after->order ?? null, $force);
        $this->catalog->reorderFeatures((object) ['order' => $before->order ?? []]);
        $this->cache->invalidateTags(['features_all']);
        foreach (['fr', 'en'] as $l) {
            $this->cache->delete('features_all_' . $l);
        }

        return ['resourceId' => null, 'before' => ['order' => $current], 'after' => ['order' => $before->order ?? []],
            'result' => array_map(fn (int $id) => $this->outputs->output('features', $this->catalog->find(\App\Entity\Feature::class, $id, 'Atout'), $locale, $host), $this->catalog->featureOrder())];
    }

    private function entreprise(int $id, array $before, array $after, string $locale, bool $force, string $host): array
    {
        $current = $this->entreprises->snapshot($id, array_keys($before), $locale) ?? throw new ContentAuditException(404, 'Fiche entreprise introuvable.');
        $this->guard($current, $after, $force);
        $result = $this->entreprises->updateEntreprise($id, $before, $this->urls->getPublicHost($host), $locale);
        $this->cache->invalidateTags(['entreprise', 'entreprise_' . $id]);
        $this->cache->delete("entreprise_{$id}_{$locale}");

        return ['resourceId' => $id, 'before' => $current, 'after' => $before, 'result' => $result];
    }

    private function settings(?string $before, ?string $after, bool $force): array
    {
        $setting = $this->settings->findOrCreateSettings();
        $current = $this->settings->getRawConfiguration($setting);
        $this->guard(json_decode((string) $current, true), json_decode((string) $after, true), $force);
        $configuration = json_decode((string) $before, false, 512, JSON_BIGINT_AS_STRING);
        $errors = $this->validator->validateConfiguration($configuration);
        if ($errors) {
            throw new ContentAuditException(422, 'Ces réglages ne passent plus les contrôles actuels : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], array_slice($errors, 0, 50));
        }
        $this->settings->updateSettings($setting, is_object($configuration) ? get_object_vars($configuration) : []);

        return ['resourceId' => $setting->getId(), 'before' => $current, 'after' => $before, 'result' => null];
    }

    private function boutiqueSettings(?string $before, ?string $after, bool $force): array
    {
        $setting = $this->boutiqueSettings->findOrCreateSettings();
        $current = $this->boutiqueSettings->getRawConfiguration($setting);
        $this->guard(json_decode((string) $current, true), json_decode((string) $after, true), $force);
        $configuration = json_decode((string) $before, false, 512, JSON_BIGINT_AS_STRING);
        $errors = $this->boutiqueValidator->validateConfiguration($configuration);
        if ($errors) {
            throw new ContentAuditException(422, 'Ces réglages ne passent plus les contrôles actuels : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], array_slice($errors, 0, 50));
        }
        $this->boutiqueSettings->updateSettings($setting, get_object_vars($configuration));

        return ['resourceId' => $setting->getId(), 'before' => $current, 'after' => $before, 'result' => null];
    }

    private function siteModel(ContentAuditLog $entry, mixed $before, bool $force): array
    {
        $app = LandingSiteModelService::appFromResource($entry->getResource());
        $fields = (object) ['name' => $before->name ?? '', 'description' => $before->description ?? null, 'configuration' => $before->configuration ?? new \stdClass()];
        if ($entry->getAction() === 'delete') {
            $model = $this->models->create($app, $fields, $entry->getUser());

            return ['resourceId' => $model->getId(), 'before' => null, 'after' => LandingSiteModelSummaryDto::fullJson($model), 'result' => LandingSiteModelSummaryDto::fromEntity($model)];
        }
        $id = (int) $entry->getResourceId();
        $current = LandingSiteModelSummaryDto::fullJson($this->models->get($id, $app));
        $strip = fn ($json) => array_diff_key((array) json_decode((string) $json, true), ['updatedAt' => 1, 'createdAt' => 1]);
        $this->guard($strip($current), $strip($entry->getAfter()), $force);
        $model = $this->models->update($id, $app, $fields);

        return ['resourceId' => $id, 'before' => $current, 'after' => LandingSiteModelSummaryDto::fullJson($model), 'result' => LandingSiteModelSummaryDto::fromEntity($model)];
    }

    private function mediaTitle(int $id, mixed $before, mixed $after, bool $force, string $host): array
    {
        $media = $this->media->find($id) ?? throw new ContentAuditException(404, 'Média supprimé depuis.');
        $this->guard($media->getTitre(), $after->title ?? null, $force);
        $current = (string) $media->getTitre();
        $this->media->rename($media, (string) ($before->title ?? $current));

        return ['resourceId' => $id, 'before' => ['title' => $current], 'after' => ['title' => $media->getTitre()], 'result' => $this->media->item($media, $host)];
    }

    /** La ressource doit être dans l'état « après » de l'écriture, sauf demande explicite */
    private function guard(mixed $current, mixed $after, bool $force): void
    {
        if (!$force && json_encode(self::normalize($current)) !== json_encode(self::normalize($after))) {
            throw new ContentAuditException(409, 'La ressource a été modifiée depuis cette écriture : relisez l\'historique, ou confirmez le retour en arrière (?force=1).');
        }
    }

    private static function normalize(mixed $value): mixed
    {
        $value = json_decode(json_encode($value), true);
        if (is_array($value)) {
            ksort($value);
        }

        return $value;
    }
}
