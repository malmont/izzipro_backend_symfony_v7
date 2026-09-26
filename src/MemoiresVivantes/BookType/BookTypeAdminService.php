<?php

namespace App\MemoiresVivantes\BookType;

use App\MemoiresVivantes\Entity\BookType;
use App\MemoiresVivantes\Entity\BookTypeChapter;
use App\MemoiresVivantes\Entity\BookTypeRole;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\MemoiresVivantes\Services\ChapterQuestionProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Règles métier de l'administration des types de livre, de leurs chapitres, rôles et questions.
 *
 * Principe : rien de ce qui est déjà utilisé par un livre ne peut être supprimé ou renommé
 * (les livres, chapitres, contributeurs et questions sont reliés par code et par texte).
 */
class BookTypeAdminService
{
    /**
     * Moteur de génération générique (DatabasePromptEngine) disponible : un type sur consignes "base"
     * peut être activé, et un type historique peut basculer sur ses consignes en base.
     */
    public const GENERIC_ENGINE_AVAILABLE = true;

    private const CODE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    private function em(): EntityManagerInterface
    {
        return $this->emProvider->getEntityManager();
    }

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    public function createType(array $data): BookType
    {
        $code = $this->requireCode($data, 'code', 50);
        if ($this->em()->getRepository(BookType::class)->findOneBy(['code' => $code])) {
            throw BookTypeAdminException::conflict("Un type de livre avec le code « {$code} » existe déjà.");
        }

        $family = $data['family'] ?? null;
        if (!in_array($family, [BookType::FAMILY_DIRECT, BookType::FAMILY_COLLECTIVE], true)) {
            throw BookTypeAdminException::invalid('La famille doit être « direct » (1 ou 2 interlocuteurs) ou « collectif » (plusieurs contributeurs).');
        }

        $type = (new BookType())
            ->setCode($code)
            ->setFamily($family)
            ->setPromptSource(BookType::PROMPT_SOURCE_DATABASE)
            ->setIsSystem(false)
            ->setIsActive(false)
            ->setSpeakerCount($family === BookType::FAMILY_DIRECT ? 1 : null);

        $this->applyTypeFields($type, $data, true);
        $this->em()->persist($type);
        $this->em()->flush();

        return $type;
    }

    public function updateType(BookType $type, array $data): BookType
    {
        if (array_key_exists('code', $data) && $data['code'] !== $type->getCode()) {
            throw BookTypeAdminException::invalid('Le code d\'un type de livre ne peut pas être modifié.');
        }
        if (array_key_exists('family', $data) && $data['family'] !== $type->getFamily()) {
            throw BookTypeAdminException::invalid('La famille d\'un type de livre ne peut pas être modifiée. Créez un nouveau type.');
        }

        $this->applyTypeFields($type, $data, false);
        $this->em()->flush();

        return $type;
    }

    public function deleteType(BookType $type): void
    {
        if ($type->isSystem()) {
            throw BookTypeAdminException::conflict('Les types historiques ne peuvent pas être supprimés. Vous pouvez les désactiver.');
        }
        $books = $this->countBooks($type);
        if ($books > 0) {
            throw BookTypeAdminException::conflict("Ce type est utilisé par {$books} livre(s) : il ne peut pas être supprimé. Vous pouvez le désactiver.");
        }

        $this->em()->createQuery('DELETE FROM ' . MemoireQuestion::class . ' q WHERE q.bookType = :type')
            ->setParameter('type', $type->getCode())
            ->execute();
        $this->em()->remove($type);
        $this->em()->flush();
    }

    private function applyTypeFields(BookType $type, array $data, bool $isCreation): void
    {
        if ($isCreation || array_key_exists('label', $data)) {
            $type->setLabel($this->requireString($data, 'label', 255, 'Le libellé'));
        }
        if (array_key_exists('description', $data)) {
            $type->setDescription($this->optionalString($data['description']));
        }
        if (array_key_exists('displayOrder', $data)) {
            $type->setDisplayOrder((int) $data['displayOrder']);
        }
        if (array_key_exists('promptRaw', $data)) {
            $type->setPromptRaw($this->optionalString($data['promptRaw']));
        }
        if (array_key_exists('promptOptimized', $data)) {
            $type->setPromptOptimized($this->optionalString($data['promptOptimized']));
        }

        if ($type->isDirect()) {
            if (array_key_exists('speakerCount', $data) && (int) $data['speakerCount'] !== $type->getSpeakerCount()) {
                $this->setSpeakerCount($type, (int) $data['speakerCount']);
            }
            if (array_key_exists('speaker1Label', $data)) {
                $type->setSpeaker1Label($this->optionalString($data['speaker1Label'], 100));
            }
            if (array_key_exists('speaker2Label', $data)) {
                $type->setSpeaker2Label($this->optionalString($data['speaker2Label'], 100));
            }
        } else {
            if (array_key_exists('speaker1Label', $data)) {
                $type->setSpeaker1Label($this->optionalString($data['speaker1Label'], 100));
            }
            if (array_key_exists('speaker2Label', $data)) {
                $type->setSpeaker2Label($this->optionalString($data['speaker2Label'], 100));
            }
            if (array_key_exists('subjectsMayBeAbsent', $data)) {
                $type->setSubjectsMayBeAbsent((bool) $data['subjectsMayBeAbsent']);
            }
            if (array_key_exists('defaultRole', $data)) {
                $type->setDefaultRole($this->optionalRoleCode($type, $data['defaultRole']));
            }
            if (array_key_exists('defaultRoleWhenSubjectsAbsent', $data)) {
                $type->setDefaultRoleWhenSubjectsAbsent($this->optionalRoleCode($type, $data['defaultRoleWhenSubjectsAbsent']));
            }
        }

        if (array_key_exists('promptSource', $data) && $data['promptSource'] !== $type->getPromptSource()) {
            if ($data['promptSource'] === BookType::PROMPT_SOURCE_CODE && !$type->isSystem()) {
                throw BookTypeAdminException::invalid('Seuls les types historiques ont des consignes dans le code.');
            }
            if ($data['promptSource'] === BookType::PROMPT_SOURCE_DATABASE && !self::GENERIC_ENGINE_AVAILABLE) {
                throw BookTypeAdminException::invalid('Le passage sur les consignes en base sera disponible avec le moteur de génération (prochaine étape).');
            }
            try {
                $type->setPromptSource((string) $data['promptSource']);
            } catch (\InvalidArgumentException $e) {
                throw BookTypeAdminException::invalid($e->getMessage());
            }
        }

        if (array_key_exists('isActive', $data)) {
            $isActive = (bool) $data['isActive'];
            if ($isActive && $type->getPromptSource() === BookType::PROMPT_SOURCE_DATABASE && !self::GENERIC_ENGINE_AVAILABLE) {
                throw BookTypeAdminException::invalid('Ce type pourra être activé quand le moteur de génération sur consignes en base sera disponible (prochaine étape).');
            }
            $type->setIsActive($isActive);
        }
    }

    private function setSpeakerCount(BookType $type, int $count): void
    {
        if (!in_array($count, [1, 2], true)) {
            throw BookTypeAdminException::invalid('Un récit direct a 1 ou 2 interlocuteurs.');
        }
        if ($type->getId() !== null && $this->countBooks($type) > 0) {
            throw BookTypeAdminException::conflict('Le nombre d\'interlocuteurs ne peut plus être modifié : des livres utilisent déjà ce type.');
        }
        if ($count === 1) {
            foreach ($type->getChapters() as $chapter) {
                if ($chapter->getSpeaker() !== BookTypeChapter::SPEAKER_PERSON1) {
                    throw BookTypeAdminException::invalid("Le chapitre « {$chapter->getTitle()} » fait parler le 2e interlocuteur : modifiez-le avant de passer à 1 interlocuteur.");
                }
            }
        }
        $type->setSpeakerCount($count);
    }

    // ------------------------------------------------------------------
    // Chapitres
    // ------------------------------------------------------------------

    public function addChapter(BookType $type, array $data): BookTypeChapter
    {
        $code = $this->requireCode($data, 'code', 100);
        if ($type->getChapter($code)) {
            throw BookTypeAdminException::conflict("Ce type a déjà un chapitre avec le code « {$code} ».");
        }

        $position = 0;
        foreach ($type->getChapters() as $existing) {
            $position = max($position, $existing->getPosition());
        }

        $chapter = (new BookTypeChapter())
            ->setCode($code)
            ->setPosition($position + 1)
            ->setBookType($type);

        // Validation avant de rattacher le chapitre à la collection : un chapitre refusé ne doit pas être persisté
        $this->applyChapterFields($chapter, $data, true);
        $type->addChapter($chapter);
        $this->em()->persist($chapter);
        $this->em()->flush();

        return $chapter;
    }

    public function updateChapter(BookTypeChapter $chapter, array $data): BookTypeChapter
    {
        if (array_key_exists('code', $data) && $data['code'] !== $chapter->getCode()) {
            throw BookTypeAdminException::invalid('Le code d\'un chapitre ne peut pas être modifié.');
        }

        $this->applyChapterFields($chapter, $data, false);
        $this->em()->flush();

        return $chapter;
    }

    public function deleteChapter(BookTypeChapter $chapter): void
    {
        $used = $this->countBookChapters($chapter);
        if ($used > 0) {
            throw BookTypeAdminException::conflict("Ce chapitre existe déjà dans {$used} livre(s) : il ne peut pas être supprimé. Vous pouvez le désactiver pour les nouveaux livres.");
        }

        // Aucun livre n'a ce chapitre : ses questions n'ont donc aucune réponse
        $this->em()->createQuery('DELETE FROM ' . MemoireQuestion::class . ' q WHERE q.bookType = :type AND q.theme = :theme')
            ->setParameter('type', $chapter->getBookType()->getCode())
            ->setParameter('theme', $chapter->getCode())
            ->execute();

        $chapter->getBookType()->removeChapter($chapter);
        $this->em()->remove($chapter);
        $this->em()->flush();
    }

    /**
     * @param int[] $orderedIds tous les chapitres du type, dans le nouvel ordre
     */
    public function reorderChapters(BookType $type, array $orderedIds): void
    {
        $chapters = [];
        foreach ($type->getChapters() as $chapter) {
            $chapters[$chapter->getId()] = $chapter;
        }

        $orderedIds = array_map('intval', $orderedIds);
        if (count($orderedIds) !== count($chapters) || array_diff($orderedIds, array_keys($chapters))) {
            throw BookTypeAdminException::invalid('L\'ordre doit contenir exactement tous les chapitres du type.');
        }

        foreach (array_values($orderedIds) as $i => $id) {
            $chapters[$id]->setPosition($i + 1);
        }
        $this->em()->flush();
    }

    private function applyChapterFields(BookTypeChapter $chapter, array $data, bool $isCreation): void
    {
        $type = $chapter->getBookType();

        if ($isCreation || array_key_exists('title', $data)) {
            $chapter->setTitle($this->requireString($data, 'title', 255, 'Le titre du chapitre'));
        }

        if ($isCreation || array_key_exists('speaker', $data)) {
            $speaker = $data['speaker'] ?? ($type->isDirect() ? BookTypeChapter::SPEAKER_PERSON1 : BookTypeChapter::SPEAKER_CONTRIBUTORS);
            $allowed = $type->isDirect()
                ? ($type->getSpeakerCount() === 2 ? BookTypeChapter::SPEAKERS_DIRECT : [BookTypeChapter::SPEAKER_PERSON1])
                : BookTypeChapter::SPEAKERS_COLLECTIVE;
            if (!in_array($speaker, $allowed, true)) {
                throw BookTypeAdminException::invalid('Interlocuteur du chapitre invalide pour ce type. Valeurs possibles : ' . implode(', ', $allowed) . '.');
            }
            $chapter->setSpeaker($speaker);
        }

        if (array_key_exists('promptRaw', $data)) {
            $chapter->setPromptRaw($this->optionalString($data['promptRaw']));
        }
        if (array_key_exists('promptOptimized', $data)) {
            $chapter->setPromptOptimized($this->optionalString($data['promptOptimized']));
        }
        if (array_key_exists('isActive', $data)) {
            $chapter->setIsActive((bool) $data['isActive']);
        }
    }

    // ------------------------------------------------------------------
    // Rôles (famille "collectif")
    // ------------------------------------------------------------------

    public function addRole(BookType $type, array $data): BookTypeRole
    {
        if (!$type->isCollective()) {
            throw BookTypeAdminException::invalid('Les rôles de contributeurs ne concernent que les types collectifs.');
        }

        $code = $this->requireCode($data, 'code', 50);
        if ($type->getRole($code)) {
            throw BookTypeAdminException::conflict("Ce type a déjà un rôle avec le code « {$code} ».");
        }

        $order = 0;
        foreach ($type->getRoles() as $existing) {
            $order = max($order, $existing->getDisplayOrder());
        }

        $role = (new BookTypeRole())
            ->setCode($code)
            ->setLabel($this->requireString($data, 'label', 255, 'Le libellé du rôle'))
            ->setDisplayOrder(isset($data['displayOrder']) ? (int) $data['displayOrder'] : $order + 1);
        $type->addRole($role);

        $this->em()->persist($role);
        $this->em()->flush();

        return $role;
    }

    public function updateRole(BookTypeRole $role, array $data): BookTypeRole
    {
        if (array_key_exists('code', $data) && $data['code'] !== $role->getCode()) {
            throw BookTypeAdminException::invalid('Le code d\'un rôle ne peut pas être modifié.');
        }
        if (array_key_exists('label', $data)) {
            $role->setLabel($this->requireString($data, 'label', 255, 'Le libellé du rôle'));
        }
        if (array_key_exists('displayOrder', $data)) {
            $role->setDisplayOrder((int) $data['displayOrder']);
        }
        $this->em()->flush();

        return $role;
    }

    public function deleteRole(BookTypeRole $role): void
    {
        $type = $role->getBookType();

        $contributors = $this->countContributors($role);
        if ($contributors > 0) {
            throw BookTypeAdminException::conflict("Ce rôle est attribué à {$contributors} contributeur(s) : il ne peut pas être supprimé.");
        }
        $questions = $this->em()->getRepository(MemoireQuestion::class)->count(['bookType' => $type->getCode(), 'role' => $role->getCode()]);
        if ($questions > 0) {
            throw BookTypeAdminException::conflict("Ce rôle a encore {$questions} question(s) : supprimez-les d'abord.");
        }
        if (in_array($role->getCode(), [$type->getDefaultRole(), $type->getDefaultRoleWhenSubjectsAbsent()], true)) {
            throw BookTypeAdminException::conflict('Ce rôle est le rôle par défaut du type : changez d\'abord le rôle par défaut.');
        }

        $type->removeRole($role);
        $this->em()->remove($role);
        $this->em()->flush();
    }

    // ------------------------------------------------------------------
    // Questions
    // ------------------------------------------------------------------

    public function addQuestion(BookTypeChapter $chapter, array $data): MemoireQuestion
    {
        $type = $chapter->getBookType();

        $maxOrder = $this->em()->createQuery('SELECT MAX(q.displayOrder) FROM ' . MemoireQuestion::class . ' q WHERE q.bookType = :type AND q.theme = :theme')
            ->setParameter('type', $type->getCode())
            ->setParameter('theme', $chapter->getCode())
            ->getSingleScalarResult();

        $question = (new MemoireQuestion())
            ->setBookType($type->getCode())
            ->setTheme($chapter->getCode())
            ->setDisplayOrder($maxOrder === null ? 0 : (int) $maxOrder + 1)
            ->setIsActive(true);

        $this->applyQuestionFields($question, $type, $data, true);
        $this->assertUniqueText($question);

        $this->em()->persist($question);
        $this->em()->flush();

        return $question;
    }

    public function updateQuestion(MemoireQuestion $question, array $data): MemoireQuestion
    {
        foreach (['bookType', 'theme'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== ($field === 'bookType' ? $question->getBookType() : $question->getTheme())) {
                throw BookTypeAdminException::invalid('Une question ne peut pas changer de type ou de chapitre : créez-en une nouvelle.');
            }
        }

        $type = $this->typeOfQuestion($question);
        $this->applyQuestionFields($question, $type, $data, false);
        if (array_key_exists('isActive', $data)) {
            $question->setIsActive((bool) $data['isActive']);
        }
        if (array_key_exists('questionText', $data) || array_key_exists('role', $data)) {
            $this->assertUniqueText($question);
        }
        $question->setUpdatedAt(new \DateTime());

        // Le changement de texte est répercuté sur les réponses existantes par MemoireQuestionAnswerSyncListener
        $this->em()->flush();

        return $question;
    }

    /**
     * Supprime la question si aucun livre n'y a répondu, sinon l'archive :
     * elle disparaît pour les nouveaux livres mais reste visible pour les livres qui y ont répondu.
     *
     * @return string "deleted" ou "archived"
     */
    public function deleteQuestion(MemoireQuestion $question): string
    {
        if ($this->isQuestionAnswered($question)) {
            $question->setIsActive(false);
            $question->setUpdatedAt(new \DateTime());
            $this->em()->flush();

            return 'archived';
        }

        $this->em()->remove($question);
        $this->em()->flush();

        return 'deleted';
    }

    /**
     * Réordonne des questions d'un chapitre en réutilisant leurs positions actuelles :
     * les questions non listées (autres rôles) gardent leur place.
     *
     * @param int[] $orderedIds
     */
    public function reorderQuestions(BookTypeChapter $chapter, array $orderedIds): void
    {
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));
        if (empty($orderedIds)) {
            throw BookTypeAdminException::invalid('Aucune question à réordonner.');
        }

        $questions = $this->em()->getRepository(MemoireQuestion::class)->findBy([
            'id' => $orderedIds,
            'bookType' => $chapter->getBookType()->getCode(),
            'theme' => $chapter->getCode(),
        ]);
        if (count($questions) !== count($orderedIds)) {
            throw BookTypeAdminException::invalid('Certaines questions n\'appartiennent pas à ce chapitre.');
        }

        $byId = [];
        $slots = [];
        foreach ($questions as $q) {
            $byId[$q->getId()] = $q;
            $slots[] = $q->getDisplayOrder();
        }
        sort($slots);

        foreach ($orderedIds as $i => $id) {
            if ($byId[$id]->getDisplayOrder() !== $slots[$i]) {
                $byId[$id]->setDisplayOrder($slots[$i]);
                $byId[$id]->setUpdatedAt(new \DateTime());
            }
        }
        $this->em()->flush();
    }

    private function applyQuestionFields(MemoireQuestion $question, BookType $type, array $data, bool $isCreation): void
    {
        if ($isCreation || array_key_exists('questionText', $data)) {
            $question->setQuestionText($this->requireString($data, 'questionText', null, 'L\'intitulé de la question'));
        }
        if (array_key_exists('tip', $data)) {
            $question->setTip($this->optionalString($data['tip']));
        }
        if ($isCreation || array_key_exists('role', $data)) {
            $role = $data['role'] ?? null;
            if ($role !== null && $role !== '') {
                if (!$type->isCollective() || !$type->getRole((string) $role)) {
                    throw BookTypeAdminException::invalid("Le rôle « {$role} » n'existe pas pour ce type de livre.");
                }
                $question->setRole((string) $role);
            } else {
                $question->setRole(null);
            }
        }
    }

    private function assertUniqueText(MemoireQuestion $question): void
    {
        // Les réponses sont appariées par texte : deux questions identiques pour le même public seraient confondues
        $qb = $this->em()->getRepository(MemoireQuestion::class)->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.bookType = :type AND q.theme = :theme AND q.questionText = :text')
            ->setParameter('type', $question->getBookType())
            ->setParameter('theme', $question->getTheme())
            ->setParameter('text', $question->getQuestionText());
        if ($question->getId() !== null) {
            $qb->andWhere('q.id <> :id')->setParameter('id', $question->getId());
        }
        if ($question->getRole() !== null) {
            $qb->andWhere('(q.role IS NULL OR q.role = :role)')->setParameter('role', $question->getRole());
        }

        if ((int) $qb->getQuery()->getSingleScalarResult() > 0) {
            throw BookTypeAdminException::conflict('Une question identique existe déjà dans ce chapitre pour ce public.');
        }
    }

    // ------------------------------------------------------------------
    // Utilisation par les livres existants
    // ------------------------------------------------------------------

    public function countBooks(BookType $type): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM mv_book WHERE type = :type',
            ['type' => $type->getCode()]
        );
    }

    public function countBookChapters(BookTypeChapter $chapter): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM mv_chapter c JOIN mv_book b ON b.id = c.book_id WHERE b.type = :type AND c.theme = :theme',
            ['type' => $chapter->getBookType()->getCode(), 'theme' => $chapter->getCode()]
        );
    }

    public function countContributors(BookTypeRole $role): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM mv_contributor co JOIN mv_book b ON b.id = co.book_id WHERE b.type = :type AND co.role = :role',
            ['type' => $role->getBookType()->getCode(), 'role' => $role->getCode()]
        );
    }

    public function isQuestionAnswered(MemoireQuestion $question): bool
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT c.answers, c.contributor_answers FROM mv_chapter c JOIN mv_book b ON b.id = c.book_id WHERE b.type = :type AND c.theme = :theme',
            ['type' => $question->getBookType(), 'theme' => $question->getTheme()]
        );

        foreach ($rows as $row) {
            $texts = ChapterQuestionProvider::answeredQuestionTextsFromData(
                json_decode($row['answers'] ?? '[]', true),
                $row['contributor_answers'] !== null ? json_decode($row['contributor_answers'], true) : null
            );
            if (in_array($question->getQuestionText(), $texts, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nombre de livres ayant une réponse non vide à chaque question du type, en une seule requête.
     * Même critère que isQuestionAnswered() : une question comptée ici sera archivée, et non supprimée.
     *
     * @return array<string, array<string, int>> [thème][texte de la question] => nombre de livres
     */
    public function answeredBooksByQuestion(BookType $type): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT c.book_id, c.theme, c.answers, c.contributor_answers FROM mv_chapter c JOIN mv_book b ON b.id = c.book_id WHERE b.type = :type',
            ['type' => $type->getCode()]
        );

        $books = [];
        foreach ($rows as $row) {
            $texts = ChapterQuestionProvider::answeredQuestionTextsFromData(
                json_decode($row['answers'] ?? '[]', true),
                $row['contributor_answers'] !== null ? json_decode($row['contributor_answers'], true) : null
            );
            foreach ($texts as $text) {
                $books[$row['theme']][$text][$row['book_id']] = true;
            }
        }

        return array_map(fn (array $byText) => array_map('count', $byText), $books);
    }

    public function typeOfQuestion(MemoireQuestion $question): BookType
    {
        $type = $this->em()->getRepository(BookType::class)->findOneBy(['code' => $question->getBookType()]);
        if (!$type) {
            throw BookTypeAdminException::invalid("Le type « {$question->getBookType()} » de cette question n'existe pas.");
        }
        return $type;
    }

    // ------------------------------------------------------------------
    // Validation des champs
    // ------------------------------------------------------------------

    private function requireCode(array $data, string $key, int $maxLength): string
    {
        $code = trim((string) ($data[$key] ?? ''));
        if ($code === '' || strlen($code) > $maxLength || !preg_match(self::CODE_PATTERN, $code)) {
            throw BookTypeAdminException::invalid("Le code doit commencer par une lettre et ne contenir que des minuscules, chiffres et _ ({$maxLength} caractères max).");
        }
        return $code;
    }

    private function requireString(array $data, string $key, ?int $maxLength, string $label): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') {
            throw BookTypeAdminException::invalid("{$label} est obligatoire.");
        }
        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            throw BookTypeAdminException::invalid("{$label} ne doit pas dépasser {$maxLength} caractères.");
        }
        return $value;
    }

    private function optionalString(mixed $value, ?int $maxLength = null): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            throw BookTypeAdminException::invalid("Un champ dépasse {$maxLength} caractères.");
        }
        return $value;
    }

    private function optionalRoleCode(BookType $type, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!$type->getRole((string) $value)) {
            throw BookTypeAdminException::invalid("Le rôle « {$value} » n'existe pas pour ce type de livre.");
        }
        return (string) $value;
    }
}
