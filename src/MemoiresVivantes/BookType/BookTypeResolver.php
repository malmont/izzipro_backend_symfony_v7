<?php

namespace App\MemoiresVivantes\BookType;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookType;
use App\Services\TenantEntityManagerProvider;

/**
 * Relie un livre (mv_book.type, simple code) à son type configuré en base, avec un repli
 * sur le comportement historique pour les tenants qui n'ont pas encore de types en base.
 */
class BookTypeResolver
{
    /** Codes historiques, toujours acceptés même sans type en base */
    public const LEGACY_CODES = ['individuel', 'couple', 'famille', 'hommage'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    public function find(?string $code): ?BookType
    {
        if ($code === null || $code === '') {
            return null;
        }
        try {
            return $this->emProvider->getEntityManager()->getRepository(BookType::class)->findOneBy(['code' => $code]);
        } catch (\Throwable) {
            // Tenant sans tables mv_book_type
            return null;
        }
    }

    /**
     * Type configuré en base dont la génération utilise les consignes en base (moteur générique),
     * null si le livre relève des consignes historiques du code.
     */
    public function findDatabasePromptType(?Book $book): ?BookType
    {
        $type = $this->find($book?->getType());

        return $type !== null && $type->getPromptSource() === BookType::PROMPT_SOURCE_DATABASE ? $type : null;
    }

    /**
     * Interlocuteur de chaque chapitre du type du livre : thème du chapitre => person1, person2, both, contributors
     * ou synthesis. Le frontend s'en sert pour présenter un chapitre de synthèse (rédigé à partir de tout le livre)
     * sans questionnaire. Type absent de la base : repli sur le catalogue historique.
     *
     * @return array<string, string>
     */
    public function speakersByTheme(?Book $book): array
    {
        $code = $book?->getType();
        $speakers = [];

        $type = $this->find($code);
        if ($type !== null) {
            foreach ($type->getChapters() as $chapter) {
                $speakers[$chapter->getCode()] = $chapter->getSpeaker();
            }

            return $speakers;
        }

        foreach (LegacyBookTypeCatalog::all() as $legacy) {
            if ($legacy['code'] === $code) {
                foreach ($legacy['chapters'] as $chapter) {
                    $speakers[$chapter['code']] = $chapter['speaker'];
                }
            }
        }

        return $speakers;
    }

    /**
     * Code accepté à la création / modification d'un livre : type historique, ou type actif en base.
     */
    public function isSelectable(string $code): bool
    {
        if (in_array($code, self::LEGACY_CODES, true)) {
            return true;
        }
        $type = $this->find($code);

        return $type !== null && $type->isActive();
    }

    /**
     * Rôle utilisé pour filtrer les questions quand aucun contributeur n'est identifié.
     */
    public function defaultRole(?Book $book): ?string
    {
        if ($book === null) {
            return null;
        }
        $subjectsAbsent = $book->isParentsDeceased() || $book->isParentsNotParticipating();

        $type = $this->find($book->getType());
        if ($type !== null && $type->isCollective() && ($type->getDefaultRole() !== null || $type->getDefaultRoleWhenSubjectsAbsent() !== null)) {
            return $subjectsAbsent && $type->isSubjectsMayBeAbsent()
                ? ($type->getDefaultRoleWhenSubjectsAbsent() ?? $type->getDefaultRole())
                : $type->getDefaultRole();
        }

        // Comportement historique (tenants sans types en base)
        if ($book->getType() === 'famille') {
            return $subjectsAbsent ? 'enfant' : 'parent';
        }

        return null;
    }
}
