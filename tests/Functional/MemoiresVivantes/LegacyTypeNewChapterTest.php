<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\MemoiresVivantes\BookType\BookTypeResolver;
use App\MemoiresVivantes\BookType\DatabasePromptEngine;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Message\GenerateChapterHandler;
use App\MemoiresVivantes\Message\GenerateChapterMessage;
use App\MemoiresVivantes\Services\ChapterTextFormatter;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeAnthropicService;
use Symfony\Component\Uid\Uuid;

/**
 * Chapitre ajouté dans la console à un type historique (consignes « dans le code ») : il est rédigé avec les
 * consignes saisies en base. Avant le 02/10/2026, le code ne le connaissant pas, il recevait la consigne du dernier
 * chapitre d'origine (un chapitre « Anecdotes en vrac » du type Famille sortait en lettre d'épilogue).
 */
class LegacyTypeNewChapterTest extends BookTypeApiTestCase
{
    public function testAChapterAddedToALegacyTypeIsWrittenWithItsOwnInstruction(): void
    {
        $family = $this->typeByCode('famille');
        $this->assertSame('code', $family['promptSource']);
        $code = self::uniqueCode('anecdotes');
        [$status, $updated] = $this->api('POST', "/admin/book-types/{$family['id']}/chapters", ['code' => $code, 'title' => 'Anecdotes en vrac', 'speaker' => 'contributors',
            'promptRaw' => 'Une anecdote = un court paragraphe autonome. Ne les fonds pas en un seul récit.']);
        $this->assertSame(201, $status, json_encode($updated));
        $typeChapterId = $this->chapter($updated['bookType'], $code)['id'];
        $this->api('POST', "/admin/book-type-chapters/$typeChapterId/questions", ['questionText' => 'Racontez une anecdote de famille.', 'role' => null]);

        [, $book] = $this->api('POST', '/books', ['title' => 'Famille de test', 'type' => 'famille', 'person1FirstName' => 'Richard', 'person2FirstName' => 'Émilie'], 'client');
        [, $newChapter] = $this->api('POST', "/books/{$book['id']}/chapters", ['title' => 'Anecdotes en vrac', 'theme' => $code, 'position' => 7, 'answers' => []], 'client');
        [, $legacyChapter] = $this->api('POST', "/books/{$book['id']}/chapters", ['title' => 'Paroles d\'enfants', 'theme' => 'regards_croises', 'position' => 2, 'answers' => []], 'client');
        [, $contributor] = $this->api('POST', "/books/{$book['id']}/contributors", ['firstName' => 'Loane', 'role' => 'enfant'], 'client');
        $this->api('PUT', "/chapters/{$newChapter['id']}", ['contributorAnswers' => [['id' => $contributor['id'], 'contributorName' => 'Loane', 'role' => 'enfant',
            'answers' => [['index' => 0, 'question' => 'Racontez une anecdote de famille.', 'answer' => 'Le jour où papa a inondé la buanderie.']]]]], 'client');

        // Le choix de la consigne se fait chapitre par chapitre
        $resolver = static::getContainer()->get(BookTypeResolver::class);
        $em = static::getContainer()->get(TenantEntityManagerProvider::class)->getEntityManager();
        $em->clear();
        $this->assertNotNull($resolver->findPromptTypeForChapter($em->getRepository(Chapter::class)->find(Uuid::fromString($newChapter['id']))), 'chapitre ajouté : consignes en base');
        $this->assertNull($resolver->findPromptTypeForChapter($em->getRepository(Chapter::class)->find(Uuid::fromString($legacyChapter['id']))), 'chapitre d\'origine : consignes du code, inchangées');

        // Rédaction par le worker : la consigne du chapitre saisie dans la console part à l'IA
        FakeAnthropicService::reset();
        $handler = static::getContainer()->get(GenerateChapterHandler::class);
        $handler(new GenerateChapterMessage($newChapter['id'], 1, MV_TEST_TENANT_CODE));
        $handler(new GenerateChapterMessage($newChapter['id'], 2, MV_TEST_TENANT_CODE));

        $this->assertCount(2, FakeAnthropicService::$calls, 'deux parties rédigées par le moteur des consignes en base');
        $prompt = FakeAnthropicService::$calls[0]['prompt'];
        $this->assertStringContainsString('Une anecdote = un court paragraphe autonome', $prompt);
        $this->assertStringContainsString('Le jour où papa a inondé la buanderie.', $prompt);
        $this->assertStringContainsString('Témoignage de Loane', $prompt);
        $row = self::db()->query("SELECT generation_status, content_final FROM mv_chapter WHERE id = '{$newChapter['id']}'")->fetch();
        $this->assertSame('completed', $row['generation_status']);
        $this->assertStringContainsString('Un paragraphe généré par la fausse IA de test.', $row['content_final']);
    }

    public function testTheSecondPartKnowsWhatTheFirstOneAlreadyTold(): void
    {
        // Chapitre court : la seconde partie recommençait un témoignage, faute de connaître toute la première
        $family = $this->typeByCode('famille');
        $code = self::uniqueCode('anecdotes');
        [, $updated] = $this->api('POST', "/admin/book-types/{$family['id']}/chapters", ['code' => $code, 'title' => 'Anecdotes', 'speaker' => 'contributors', 'promptRaw' => 'Une anecdote par paragraphe, sans conclusion.']);
        [, $book] = $this->api('POST', '/books', ['title' => 'Famille de test', 'type' => 'famille'], 'client');
        [, $chapter] = $this->api('POST', "/books/{$book['id']}/chapters", ['title' => 'Anecdotes', 'theme' => $code, 'position' => 7, 'answers' => [['index' => 0, 'question' => 'Une anecdote ?', 'answer' => 'La buanderie inondée.']]], 'client');

        $handler = static::getContainer()->get(GenerateChapterHandler::class);
        FakeAnthropicService::reset();
        FakeAnthropicService::$completions = ["Premier paragraphe sur la buanderie.\n\nDeuxième paragraphe.\n\nTroisième.\n\nQuatrième et dernier paragraphe.", DatabasePromptEngine::NOTHING_TO_ADD];
        $handler(new GenerateChapterMessage($chapter['id'], 1, MV_TEST_TENANT_CODE));
        $handler(new GenerateChapterMessage($chapter['id'], 2, MV_TEST_TENANT_CODE));

        $secondPrompt = FakeAnthropicService::$calls[1]['prompt'];
        $this->assertStringContainsString('Premier paragraphe sur la buanderie.', $secondPrompt, 'toute la première partie est transmise, pas seulement sa fin');
        $this->assertStringContainsString(DatabasePromptEngine::NOTHING_TO_ADD, $secondPrompt);
        $row = self::db()->query("SELECT generation_status, content_final FROM mv_chapter WHERE id = '{$chapter['id']}'")->fetch();
        $this->assertSame('completed', $row['generation_status']);
        $this->assertSame("Premier paragraphe sur la buanderie.\n\nDeuxième paragraphe.\n\nTroisième.\n\nQuatrième et dernier paragraphe.", $row['content_final'], 'ni répétition ni marque « rien à ajouter » dans le chapitre');

        $this->assertTrue(DatabasePromptEngine::isNothingToAdd(' [RIEN À AJOUTER]. '));
        $this->assertTrue(DatabasePromptEngine::isNothingToAdd('**[Rien a ajouter]**'));
        $this->assertFalse(DatabasePromptEngine::isNothingToAdd('Il ne reste rien à ajouter à cette histoire, sinon que nous en rions encore.'));
        $this->assertFalse(DatabasePromptEngine::isNothingToAdd(''));
    }

    public function testTheChapterTitleRepeatedByTheAiIsRemoved(): void
    {
        $formatter = new ChapterTextFormatter();

        $this->assertSame('Le carnet de caisse était ouvert.', $formatter->withoutRepeatedTitle("# Avant l'entreprise\n\nLe carnet de caisse était ouvert.", "Avant l'entreprise"));
        $this->assertSame('Je suis née en 1948.', $formatter->withoutRepeatedTitle("===L'enfance et les racines===\n\n---\n\nJe suis née en 1948.", "Chapitre 1 — 🌳 L'enfance et les racines"));
        $this->assertSame("===Terres-Sainville===\n\nJe suis née en 1948.", $formatter->withoutRepeatedTitle("===Terres-Sainville===\n\nJe suis née en 1948.", "Chapitre 1 — L'enfance"), 'un vrai sous-titre est conservé');
        $this->assertSame('Je suis née en 1948.', $formatter->withoutRepeatedTitle('Je suis née en 1948.', "L'enfance"));
    }
}
