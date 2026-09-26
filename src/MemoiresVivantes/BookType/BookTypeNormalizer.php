<?php

namespace App\MemoiresVivantes\BookType;

use App\MemoiresVivantes\Entity\BookType;
use App\MemoiresVivantes\Entity\BookTypeChapter;
use App\MemoiresVivantes\Entity\BookTypeRole;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\Services\TenantEntityManagerProvider;

/**
 * Formats JSON des types de livre : public (parcours client, sans consignes) et admin (complet).
 */
class BookTypeNormalizer
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BookTypeAdminService $adminService
    ) {}

    public function toPublicArray(BookType $type): array
    {
        $chapters = [];
        foreach ($this->sortedChapters($type) as $chapter) {
            if ($chapter->isActive()) {
                $chapters[] = [
                    'code' => $chapter->getCode(),
                    'title' => $chapter->getTitle(),
                    'position' => $chapter->getPosition(),
                    'speaker' => $chapter->getSpeaker(),
                ];
            }
        }

        return [
            'code' => $type->getCode(),
            'label' => $type->getLabel(),
            'description' => $type->getDescription(),
            'family' => $type->getFamily(),
            'speakerCount' => $type->getSpeakerCount(),
            'speaker1Label' => $type->getSpeaker1Label(),
            'speaker2Label' => $type->getSpeaker2Label(),
            'subjectsMayBeAbsent' => $type->isSubjectsMayBeAbsent(),
            'defaultRole' => $type->getDefaultRole(),
            'defaultRoleWhenSubjectsAbsent' => $type->getDefaultRoleWhenSubjectsAbsent(),
            'displayOrder' => $type->getDisplayOrder(),
            'chapters' => $chapters,
            'roles' => array_map(fn (BookTypeRole $r) => [
                'code' => $r->getCode(),
                'label' => $r->getLabel(),
            ], $this->sortedRoles($type)),
        ];
    }

    /** Résumé pour la liste admin */
    public function toAdminSummaryArray(BookType $type): array
    {
        return [
            'id' => $type->getId(),
            'code' => $type->getCode(),
            'label' => $type->getLabel(),
            'family' => $type->getFamily(),
            'speakerCount' => $type->getSpeakerCount(),
            'isActive' => $type->isActive(),
            'isSystem' => $type->isSystem(),
            'promptSource' => $type->getPromptSource(),
            'displayOrder' => $type->getDisplayOrder(),
            'chaptersCount' => $type->getChapters()->count(),
            'rolesCount' => $type->getRoles()->count(),
            'usage' => ['books' => $this->adminService->countBooks($type)],
        ];
    }

    /** Détail complet pour l'écran admin : type, consignes, chapitres avec leurs questions, rôles, utilisation */
    public function toAdminArray(BookType $type): array
    {
        $questionsByTheme = [];
        $questions = $this->emProvider->getEntityManager()->getRepository(MemoireQuestion::class)
            ->findBy(['bookType' => $type->getCode()], ['displayOrder' => 'ASC', 'id' => 'ASC']);
        foreach ($questions as $q) {
            $questionsByTheme[$q->getTheme()][] = $this->questionToArray($q);
        }

        return [
            ...$this->toAdminSummaryArray($type),
            'description' => $type->getDescription(),
            'speaker1Label' => $type->getSpeaker1Label(),
            'speaker2Label' => $type->getSpeaker2Label(),
            'subjectsMayBeAbsent' => $type->isSubjectsMayBeAbsent(),
            'defaultRole' => $type->getDefaultRole(),
            'defaultRoleWhenSubjectsAbsent' => $type->getDefaultRoleWhenSubjectsAbsent(),
            'promptRaw' => $type->getPromptRaw(),
            'promptOptimized' => $type->getPromptOptimized(),
            'createdAt' => $type->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $type->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'chapters' => array_map(fn (BookTypeChapter $c) => [
                'id' => $c->getId(),
                'code' => $c->getCode(),
                'title' => $c->getTitle(),
                'position' => $c->getPosition(),
                'speaker' => $c->getSpeaker(),
                'isActive' => $c->isActive(),
                'promptRaw' => $c->getPromptRaw(),
                'promptOptimized' => $c->getPromptOptimized(),
                'usage' => ['bookChapters' => $this->adminService->countBookChapters($c)],
                'questions' => $questionsByTheme[$c->getCode()] ?? [],
            ], $this->sortedChapters($type)),
            'roles' => array_map(fn (BookTypeRole $r) => [
                'id' => $r->getId(),
                'code' => $r->getCode(),
                'label' => $r->getLabel(),
                'displayOrder' => $r->getDisplayOrder(),
                'usage' => ['contributors' => $this->adminService->countContributors($r)],
            ], $this->sortedRoles($type)),
        ];
    }

    /** Tri explicite : la collection en mémoire ne reflète pas un réordonnancement fait dans la même requête */
    private function sortedChapters(BookType $type): array
    {
        $chapters = $type->getChapters()->toArray();
        usort($chapters, fn (BookTypeChapter $a, BookTypeChapter $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);
        return $chapters;
    }

    private function sortedRoles(BookType $type): array
    {
        $roles = $type->getRoles()->toArray();
        usort($roles, fn (BookTypeRole $a, BookTypeRole $b) => [$a->getDisplayOrder(), $a->getId()] <=> [$b->getDisplayOrder(), $b->getId()]);
        return $roles;
    }

    public function questionToArray(MemoireQuestion $q): array
    {
        return [
            'id' => $q->getId(),
            'role' => $q->getRole(),
            'questionText' => $q->getQuestionText(),
            'tip' => $q->getTip(),
            'displayOrder' => $q->getDisplayOrder(),
            'isActive' => $q->isActive(),
        ];
    }
}
