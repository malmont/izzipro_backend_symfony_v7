<?php

namespace App\UseCase\LandingContentUseCase;

use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingContentService\LandingContentEditor;
use App\Services\LandingContentService\LandingContentSpec;
use App\Services\MediaUrlResolver;
use App\Services\TenantCacheService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PATCH /api/{ressource}/{id}?locale= : modifie depuis l'éditeur des landing pages les champs envoyés d'un contenu
 * de section, puis renvoie l'objet tel que le GET de la ressource le renverrait (même langue). Les objets d'un autre
 * site sont dans une autre base : ici, ils sont introuvables (404).
 */
class PatchLandingContentUseCase
{
    public function __construct(
        private readonly LandingContentEditor $editor,
        private readonly TenantCacheService $cache,
        private readonly MediaUrlResolver $mediaUrlResolver,
        private readonly ContentAuditRecorder $audit
    ) {
    }

    /**
     * @throws HttpException 400, 404 ou 422 (corps de 422 : errors[] avec path et message, voir LandingContentException)
     */
    public function execute(string $resource, int $id, mixed $body, string $locale, string $host): object
    {
        if (!preg_match('/^[a-z]{2}$/', $locale)) {
            throw new LandingContentException(400, 'Langue invalide (?locale=fr, en…).');
        }
        if (!is_object($body) || get_object_vars($body) === []) {
            throw new LandingContentException(400, 'Objet JSON attendu, avec au moins un champ à modifier.');
        }
        $entity = $this->editor->find($resource, $id);
        if ($entity === null) {
            throw new LandingContentException(404, 'Contenu introuvable sur ce site.');
        }
        ['values' => $values, 'errors' => $errors] = $this->editor->validate($resource, $body);
        if ($errors) {
            throw new LandingContentException(422, 'Champs refusés : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], $errors);
        }

        $before = $this->editor->snapshot($resource, $entity, array_keys($values), $locale);
        $this->editor->apply($resource, $entity, $values, $locale);
        $after = $this->editor->snapshot($resource, $entity, array_keys($values), $locale);
        $this->audit->record($resource, $id, 'update', $before, $after, array_keys($values), $locale);
        $this->invalidate($resource, $id);

        return $this->output($resource, $entity, $locale, $host);
    }

    private function output(string $resource, object $entity, string $locale, string $host): object
    {

        $base = LandingContentSpec::RESOURCES[$resource]['images'] === 'email-logos'
            ? $this->mediaUrlResolver->getEmailLogosBaseUrl($host)
            : $this->mediaUrlResolver->getSliderBaseUrl($host);

        return LandingContentSpec::output($resource, $entity, $base, $locale);
    }

    private function invalidate(string $resource, int $id): void
    {
        $this->cache->invalidateTags(array_map(fn ($tag) => sprintf($tag, $id), LandingContentSpec::RESOURCES[$resource]['tags']));
    }
}
