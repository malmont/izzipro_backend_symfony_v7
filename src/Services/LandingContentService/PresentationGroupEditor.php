<?php

namespace App\Services\LandingContentService;

use App\Entity\Presentation;
use App\Entity\PresentationGroup;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\LandingContentUseCase\LandingContentException;

/**
 * Composition d'un groupe de présentations depuis l'éditeur des landing pages : ajout d'une présentation créée dans
 * le groupe, retrait (la présentation est supprimée si elle n'appartient plus à aucun groupe, sinon seulement
 * détachée), et ordre d'affichage (PresentationGroup::presentationOrder).
 */
final class PresentationGroupEditor
{
    public const MAX_PRESENTATIONS = 60;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingContentEditor $editor
    ) {
    }

    /** @throws LandingContentException 404 */
    public function group(int $id): PresentationGroup
    {
        $group = $this->emProvider->getEntityManager()->getRepository(PresentationGroup::class)->find($id);

        return $group instanceof PresentationGroup ? $group : throw new LandingContentException(404, 'Groupe introuvable sur ce site.');
    }

    /**
     * Crée une présentation (champs de PATCH /api/presentations, titre obligatoire) et l'ajoute au groupe, après la
     * présentation « after » (identifiant) ou à la fin.
     *
     * @return Presentation la présentation créée
     * @throws LandingContentException 400, 404 ou 422
     */
    public function add(PresentationGroup $group, mixed $body, string $locale): Presentation
    {
        if (!is_object($body)) {
            throw new LandingContentException(400, 'Objet JSON attendu.');
        }
        $after = property_exists($body, 'after') ? $body->after : null;
        unset($body->after);
        if (!isset($body->titre)) {
            throw new LandingContentException(422, 'Champ obligatoire manquant : titre', [['path' => 'titre', 'message' => 'champ obligatoire']]);
        }
        if ($group->getPresentations()->count() >= self::MAX_PRESENTATIONS) {
            throw new LandingContentException(422, sprintf('%d présentations au plus par groupe.', self::MAX_PRESENTATIONS), [['path' => '', 'message' => 'groupe complet']]);
        }
        $order = $this->order($group);
        if ($after !== null && (!is_int($after) || !in_array($after, $order, true))) {
            throw new LandingContentException(422, 'after : identifiant d\'une présentation du groupe, ou null', [['path' => 'after', 'message' => 'présentation absente du groupe']]);
        }
        ['values' => $values, 'errors' => $errors] = $this->editor->validate('presentations', $body);
        if ($errors) {
            throw new LandingContentException(422, 'Champs refusés : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], $errors);
        }

        $em = $this->emProvider->getEntityManager();
        $presentation = (new Presentation())->setTitre((string) $values['titre']);
        foreach ($values as $field => $value) {
            $presentation->{'set' . ucfirst($field)}($value);
        }
        $em->persist($presentation);
        $group->addPresentation($presentation);
        $presentation->addPresentationGroup($group);
        $em->flush();
        // traduction de la langue demandée, comme une modification
        $this->editor->apply('presentations', $presentation, $values, $locale);

        $position = $after === null ? count($order) : array_search($after, $order, true) + 1;
        array_splice($order, $position, 0, [(int) $presentation->getId()]);
        $group->setPresentationOrder($order);
        $em->flush();

        return $presentation;
    }

    /** @throws LandingContentException 404 */
    public function remove(PresentationGroup $group, int $presentationId): void
    {
        $presentation = null;
        foreach ($group->getPresentations() as $candidate) {
            if ($candidate->getId() === $presentationId) {
                $presentation = $candidate;
            }
        }
        if ($presentation === null) {
            throw new LandingContentException(404, 'Présentation absente de ce groupe.');
        }
        $order = array_values(array_diff($this->order($group), [$presentationId]));
        $presentation->removePresentationGroup($group);
        $group->removePresentation($presentation);
        $em = $this->emProvider->getEntityManager();
        if ($presentation->getPresentationGroups()->isEmpty()) {
            $em->remove($presentation);
        }
        $group->setPresentationOrder($order);
        $em->flush();
    }

    /**
     * @throws LandingContentException 400 ou 422 : la liste doit contenir exactement les présentations du groupe
     */
    public function reorder(PresentationGroup $group, mixed $body): void
    {
        $ids = is_object($body) ? ($body->order ?? null) : null;
        if (!is_array($ids) || array_filter($ids, fn ($id) => !is_int($id))) {
            throw new LandingContentException(400, 'Objet JSON attendu : { "order": [identifiants des présentations] }.');
        }
        $current = array_map(fn (Presentation $p) => (int) $p->getId(), $group->getPresentations()->toArray());
        $sortedIds = $ids;
        sort($sortedIds);
        sort($current);
        if ($sortedIds !== $current) {
            throw new LandingContentException(422, 'order : chaque présentation du groupe, une seule fois', [['path' => 'order', 'message' => sprintf('identifiants attendus : %s', implode(', ', $current))]]);
        }
        $group->setPresentationOrder($ids);
        $this->emProvider->getEntityManager()->flush();
    }

    /** @return list<int> ordre actuel complet (ordre enregistré, puis les autres présentations) */
    private function order(PresentationGroup $group): array
    {
        return array_map(fn (Presentation $p) => (int) $p->getId(), $group->getOrderedPresentations());
    }
}
