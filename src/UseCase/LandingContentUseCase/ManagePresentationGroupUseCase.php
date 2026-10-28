<?php

namespace App\UseCase\LandingContentUseCase;

use App\Dto\PresentationGroupOutputDto;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\LandingContentService\PresentationGroupEditor;
use App\Services\MediaUrlResolver;

/**
 * POST, DELETE et PUT (ordre) /api/presentation-groups/{id}/presentations… : composition d'un groupe de
 * présentations depuis l'éditeur des landing pages. Réponse : le groupe tel que son GET le renvoie. Le cache du
 * groupe est vidé par l'écriture Doctrine elle-même (registre CacheTags, 28/10/2026).
 */
class ManagePresentationGroupUseCase
{
    public function __construct(
        private readonly PresentationGroupEditor $editor,
        private readonly MediaUrlResolver $mediaUrlResolver,
        private readonly ContentAuditRecorder $audit
    ) {
    }

    /** @throws LandingContentException */
    public function add(int $groupId, mixed $body, string $locale, string $host): PresentationGroupOutputDto
    {
        $group = $this->editor->group($groupId);
        $presentation = $this->editor->add($group, $body, self::locale($locale));
        $this->audit->record('presentation-groups', $groupId, 'add', null, ['presentation' => $presentation->getId(), 'titre' => $presentation->getTitre(), 'order' => $group->getPresentationOrder()], ['presentations'], $locale);

        return $this->done($group, $locale, $host);
    }

    /** @throws LandingContentException */
    public function remove(int $groupId, int $presentationId, string $locale, string $host): PresentationGroupOutputDto
    {
        $group = $this->editor->group($groupId);
        $before = ['presentation' => $presentationId, 'order' => array_map(fn ($p) => (int) $p->getId(), $group->getOrderedPresentations())];
        $this->editor->remove($group, $presentationId);
        $this->audit->record('presentation-groups', $groupId, 'remove', $before, ['order' => $group->getPresentationOrder()], ['presentations'], $locale);

        return $this->done($group, $locale, $host);
    }

    /** @throws LandingContentException */
    public function reorder(int $groupId, mixed $body, string $locale, string $host): PresentationGroupOutputDto
    {
        $group = $this->editor->group($groupId);
        $before = array_map(fn ($p) => (int) $p->getId(), $group->getOrderedPresentations());
        $this->editor->reorder($group, $body);
        $this->audit->record('presentation-groups', $groupId, 'reorder', ['order' => $before], ['order' => $group->getPresentationOrder()], ['order'], $locale);

        return $this->done($group, $locale, $host);
    }

    private function done(\App\Entity\PresentationGroup $group, string $locale, string $host): PresentationGroupOutputDto
    {
        return new PresentationGroupOutputDto($group, $this->mediaUrlResolver->getSliderBaseUrl($host), self::locale($locale));
    }

    private static function locale(string $locale): string
    {
        return preg_match('/^[a-z]{2}$/', $locale) ? $locale : throw new LandingContentException(400, 'Langue invalide (?locale=fr, en…).');
    }
}
