<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Message\GenerateChapterMessage;
use App\MemoiresVivantes\Services\ChapterGenerationService;
use App\MemoiresVivantes\Services\ChapterQuestionProvider;
use App\MemoiresVivantes\UseCase\TranscribeAudioUseCase;
use App\Entity\User;
use App\Services\AnthropicService;
use App\Services\TenantEntityManagerProvider;
use App\Services\Worker\WorkerMonitor;
use App\Tests\Fake\FakeAnthropicService;

/**
 * Rédaction d'un chapitre par l'IA : rien n'est envoyé sans matériau, une rédaction n'est pas lancée deux fois, une
 * rédaction perdue passe en échec. Avant le 30/09/2026, chaque chapitre créé vide partait à l'IA (refus ou texte
 * inventé enregistré comme chapitre terminé) et le prénom du compte (« Admin ») servait de prénom au narrateur.
 */
class ChapterGenerationTest extends BookTypeApiTestCase
{
    private const ANSWER = ['index' => 0, 'question' => 'Où êtes-vous né(e) ?', 'answer' => 'À Fort-de-France, en 1948.', 'improvedAnswer' => '', 'useImproved' => false];

    public function testAnEmptyChapterIsNotSentToTheAi(): void
    {
        $bookId = $this->createBook();

        [$status, $chapter] = $this->api('POST', "/books/$bookId/chapters", ['title' => 'Chapitre 1 — L\'enfance', 'theme' => 'enfance', 'position' => 1, 'answers' => []], 'client');

        $this->assertSame(201, $status);
        $this->assertSame([], $this->sentMessages(), 'aucune rédaction lancée pour un chapitre sans réponse');
        $this->assertSame('completed', $chapter['generationStatus'], 'pas de rédaction en attente');
        $this->assertNull($chapter['contentFinal']);

        [$status, $body] = $this->api('POST', "/chapters/{$chapter['id']}/generate", [], 'client');
        $this->assertSame(422, $status);
        $this->assertStringContainsString('au moins une question', $body['error']);
        $this->assertSame([], $this->sentMessages());
    }

    public function testAChapterCreatedWithAnswersIsGenerated(): void
    {
        $bookId = $this->createBook();

        [$status, $chapter] = $this->api('POST', "/books/$bookId/chapters", ['title' => 'L\'enfance', 'theme' => 'enfance', 'position' => 1, 'answers' => [self::ANSWER]], 'client');

        $this->assertSame(201, $status);
        $this->assertSame('pending', $chapter['generationStatus']);
        $this->assertCount(1, $this->sentMessages());
    }

    public function testGenerationStartsOnceEvenWhenRequestedTwice(): void
    {
        $chapterId = $this->createChapter($this->createBook());
        $this->api('PUT', "/chapters/$chapterId", ['answers' => [self::ANSWER]], 'client');

        [$status, $body] = $this->api('POST', "/chapters/$chapterId/generate", ['tone' => 'joyeux', 'model' => 'claude-fable-5-1'], 'client');
        $this->assertSame(200, $status);
        $this->assertSame('Generation started', $body['status']);
        $sent = $this->sentMessages();
        $this->assertCount(1, $sent);
        $this->assertSame([$chapterId, 1, MV_TEST_TENANT_CODE, 'joyeux', 'claude-fable-5-1'], [$sent[0]->chapterId, $sent[0]->part, $sent[0]->tenantHost, $sent[0]->tone, $sent[0]->model]);

        [$status, $body] = $this->api('POST', "/chapters/$chapterId/generate", [], 'client');
        $this->assertSame(200, $status);
        $this->assertTrue($body['alreadyInProgress']);
        $this->assertSame([], $this->sentMessages(), 'double clic : pas de seconde rédaction');
    }

    public function testALostGenerationFailsAndCanBeRestarted(): void
    {
        $chapterId = $this->createChapter($this->createBook());
        $this->api('PUT', "/chapters/$chapterId", ['answers' => [self::ANSWER]], 'client');
        self::db()->prepare("UPDATE mv_chapter SET generation_status = 'generating_part1', updated_at = now() - interval '2 hours' WHERE id = ?")->execute([$chapterId]);

        // Le frontend suit la rédaction en relisant le chapitre
        [$status, $chapter] = $this->api('GET', "/chapters/$chapterId", null, 'client');
        $this->assertSame(200, $status);
        $this->assertSame('failed', $chapter['generationStatus']);
        $this->assertStringContainsString('Relancez', $chapter['generationError']);

        [$status, $body] = $this->api('POST', "/chapters/$chapterId/generate", [], 'client');
        $this->assertSame('Generation started', $body['status'] ?? null);
        $this->assertCount(1, $this->sentMessages());
    }

    public function testAnEmptyQueueRevealsALostGenerationWithinMinutes(): void
    {
        $chapter = (new Chapter())->setTitle('T')->setTheme('enfance')->setPosition(1)->setGenerationStatus('pending');
        (new \ReflectionProperty($chapter, 'updatedAt'))->setValue($chapter, new \DateTime('-5 minutes'));

        $this->assertTrue($this->serviceWithQueue(true)->failIfLost($chapter), 'file vide : la rédaction ne viendra plus');
        $this->assertSame('failed', $chapter->getGenerationStatus());

        $waiting = (new Chapter())->setTitle('T')->setTheme('enfance')->setPosition(1)->setGenerationStatus('pending');
        (new \ReflectionProperty($waiting, 'updatedAt'))->setValue($waiting, new \DateTime('-5 minutes'));
        $this->assertFalse($this->serviceWithQueue(false)->failIfLost($waiting), 'un message attend encore dans la file');
        $this->assertSame('pending', $waiting->getGenerationStatus());

        $recent = (new Chapter())->setTitle('T')->setTheme('enfance')->setPosition(1)->setGenerationStatus('pending');
        $this->assertFalse($this->serviceWithQueue(true)->failIfLost($recent), 'délai de grâce juste après le lancement');
    }

    public function testInvalidInputIsRejectedWithoutServerError(): void
    {
        [$status] = $this->api('POST', '/books', [], 'client');
        $this->assertSame(422, $status, 'livre sans titre');
        [$status] = $this->api('POST', '/books', ['title' => ['pas', 'un', 'texte']], 'client');
        $this->assertSame(422, $status, 'titre qui n\'est pas un texte');

        $bookId = $this->createBook();
        [$status] = $this->api('POST', "/books/$bookId/chapters", [], 'client');
        $this->assertSame(422, $status, 'chapitre sans titre');

        foreach (['/books/pas-un-uuid', '/chapters/pas-un-uuid', '/books/pas-un-uuid/chapters'] as $path) {
            [$status, $body] = $this->api('GET', $path, null, 'client');
            $this->assertSame(404, $status, $path);
            $this->assertNotEmpty($body['error']);
        }
    }

    public function testAFailingAiIsNotPresentedAsAnImprovement(): void
    {
        $bookId = $this->createBook('famille');
        $chapterId = $this->createChapter($bookId, 'regards_croises');
        [, $contributor] = $this->api('POST', "/books/$bookId/contributors", ['firstName' => 'Léa', 'role' => 'enfant'], 'client');
        $link = $this->signedQuery($chapterId, $contributor['id']);
        $payload = ['question' => 'Un souvenir ?', 'answer' => 'Les dimanches à la plage.', 'index' => 0];

        FakeAnthropicService::$improvedAnswer = '';
        [$status, $body] = $this->api('POST', "/chapters/$chapterId/improve-answer?$link", $payload, null);
        $this->assertSame(503, $status);
        $this->assertTrue($body['canImprove']);

        // L'essai unique de l'invité n'a pas été consommé par la panne
        FakeAnthropicService::$improvedAnswer = 'Nos dimanches à la plage restent gravés en moi.';
        [$status, $body] = $this->api('POST', "/chapters/$chapterId/improve-answer?$link", $payload, null);
        $this->assertSame(200, $status);
        $this->assertSame('Nos dimanches à la plage restent gravés en moi.', $body['improvedText']);

        [$status] = $this->api('POST', "/chapters/$chapterId/improve-answer?$link", $payload, null);
        $this->assertSame(403, $status, 'une seule amélioration par question pour un invité');
    }

    public function testTheNarratorIsNamedAfterTheBookNotTheAccount(): void
    {
        $account = (new User())->setFirstname('Admin')->setLastname('User');
        $book = (new Book())->setTitle('Empreintes')->setUser($account);

        $this->assertNull(AnthropicService::narratorFirstName($book), 'le prénom d\'un compte générique n\'est pas celui du narrateur');
        $this->assertSame('Danielle', AnthropicService::narratorFirstName($book->setPerson1FirstName('Danielle')));
        $this->assertSame('Carole', AnthropicService::narratorFirstName((new Book())->setTitle('T')->setUser((new User())->setFirstname('Carole'))));
    }

    public function testTheTextSentToTheAiIsTheOneTheUserKept(): void
    {
        $this->assertSame('brute', ChapterQuestionProvider::answerText(['answer' => 'brute', 'improvedAnswer' => '']), 'une amélioration vide ne masque pas la réponse');
        $this->assertSame('améliorée', ChapterQuestionProvider::answerText(['answer' => 'brute', 'improvedAnswer' => 'améliorée']));
        $this->assertSame('brute', ChapterQuestionProvider::answerText(['answer' => 'brute', 'improvedAnswer' => 'améliorée', 'useImproved' => false]), 'amélioration écartée');

        $formatted = static::getContainer()->get(AnthropicService::class)->formatAnswers([
            ['question' => 'Q1', 'answer' => 'brute', 'improvedAnswer' => ''],
            ['question' => 'Q2', 'answer' => '', 'improvedAnswer' => ''],
            ['question' => 'Q3', 'answer' => 'passée', 'skipped' => true],
        ]);
        $this->assertSame("Q: Q1\nR: brute", $formatted, 'ni question sans réponse, ni question passée');
    }

    public function testCoupleAnswersAreComposedVoiceByVoice(): void
    {
        // Le frontend envoie « Elle: … ⏎ Lui: … » (saisie et amélioration), avec un choix par voix (useImproved1/2)
        $entry = ['answer' => "Elle: Née en Martinique.\nLui: À Paris.", 'improvedAnswer' => "Elle: Née en Martinique, joyau des Caraïbes.\nLui: ", 'useImproved1' => true, 'useImproved2' => false];
        $this->assertSame("Elle : Née en Martinique, joyau des Caraïbes.\nLui : À Paris.", ChapterQuestionProvider::answerText($entry));

        $entry['useImproved1'] = false;
        $this->assertSame("Elle : Née en Martinique.\nLui : À Paris.", ChapterQuestionProvider::answerText($entry), 'amélioration écartée pour la première voix');

        $this->assertSame('', ChapterQuestionProvider::answerText(['answer' => "Elle: \n Lui: ", 'improvedAnswer' => "Elle: \n Lui: ", 'useImproved1' => false, 'useImproved2' => false]), 'étiquettes sans texte : pas une réponse');
        $this->assertSame('', ChapterQuestionProvider::answerText(['answer' => "Danielle: \n Michel: "]), 'même sans les drapeaux couple');
    }

    public function testAnAiCommentIsNeverAppendedToAChapter(): void
    {
        // Un chapitre finissant par « …du monde.* » (italique Markdown) était jugé inachevé ; l'IA répondait par un
        // commentaire, « (aucune phrase n'est en cours…) », ajouté à la fin du chapitre
        $this->assertTrue(AnthropicService::endsWithFullSentence("…il y avait tout l'amour du monde.*"));
        $this->assertTrue(AnthropicService::endsWithFullSentence('Elle dit : « Reviens ! »'));
        $this->assertTrue(AnthropicService::endsWithFullSentence('Une fin en gras.**'));
        $this->assertFalse(AnthropicService::endsWithFullSentence('Une phrase coupée au milieu de'));

        $this->assertTrue(AnthropicService::looksLikeCommentary("*(aucune phrase ou paragraphe n'est en cours — le texte se termine par un point.)*"));
        $this->assertTrue(AnthropicService::looksLikeCommentary('Le texte est déjà complet.'));
        $this->assertFalse(AnthropicService::looksLikeCommentary(' ses bras, et nous sommes rentrés à la maison.'));

        $service = static::getContainer()->get(AnthropicService::class);
        $this->assertSame("Un chapitre terminé.*", $service->checkAndComplete("Un chapitre terminé.*", true), 'aucun appel à l\'IA pour un texte terminé');
        $this->assertSame([], FakeAnthropicService::$calls);
    }

    public function testASilentRecordingIsNotTranscribedAsAnAnswer(): void
    {
        $this->assertTrue(TranscribeAudioUseCase::isSilence(''));
        $this->assertTrue(TranscribeAudioUseCase::isSilence("Sous-titres réalisés para la communauté d'Amara.org"));
        $this->assertTrue(TranscribeAudioUseCase::isSilence('Sous-titrage ST\' 501'));
        $this->assertFalse(TranscribeAudioUseCase::isSilence('Je suis née à Fort-de-France.'));
        $this->assertFalse(TranscribeAudioUseCase::isSilence(str_repeat('Mon père regardait les films avec des sous-titres. ', 5)), 'un vrai récit qui emploie ces mots');
    }

    private function createBook(string $type = 'individuel'): string
    {
        [$status, $book] = $this->api('POST', '/books', ['title' => 'Livre de test', 'type' => $type, 'person1FirstName' => 'Danielle'], 'client');
        $this->assertSame(201, $status);
        $this->sentMessages();

        return $book['id'];
    }

    private function createChapter(string $bookId, string $theme = 'enfance'): string
    {
        [$status, $chapter] = $this->api('POST', "/books/$bookId/chapters", ['title' => 'Chapitre', 'theme' => $theme, 'position' => 1, 'answers' => []], 'client');
        $this->assertSame(201, $status);

        return $chapter['id'];
    }

    /** @return GenerateChapterMessage[] messages mis en file par la dernière requête */
    private function sentMessages(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        $messages = array_map(fn ($envelope) => $envelope->getMessage(), $transport->getSent());
        $transport->reset();

        return $messages;
    }

    private function signedQuery(string $chapterId, string $contributorId): string
    {
        $expires = time() + 600;
        $secret = static::getContainer()->getParameter('kernel.secret');

        return http_build_query(['chapterId' => $chapterId, 'contributorId' => $contributorId, 'expires' => $expires,
            'signature' => hash_hmac('sha256', "chapterId=$chapterId&contributorId=$contributorId&expires=$expires", $secret)]);
    }

    /** Service réel, avec une file dont l'état est imposé (le transport des tests est en mémoire) */
    private function serviceWithQueue(bool $empty): ChapterGenerationService
    {
        $container = static::getContainer();
        $container->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $monitor = $this->createMock(WorkerMonitor::class);
        $monitor->method('isQueueEmpty')->willReturn($empty);

        $service = $container->get(ChapterGenerationService::class);
        $clone = (new \ReflectionClass($service))->newInstanceWithoutConstructor();
        foreach ((new \ReflectionClass($service))->getProperties() as $property) {
            $property->setValue($clone, $property->getName() === 'workerMonitor' ? $monitor : $property->getValue($service));
        }

        return $clone;
    }
}
