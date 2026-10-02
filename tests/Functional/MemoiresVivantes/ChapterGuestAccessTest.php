<?php

namespace App\Tests\Functional\MemoiresVivantes;

/**
 * Liens de partage et de contribution : un invité modifie des réponses (et le texte final par le lien de partage du
 * chapitre, jamais par un lien de contributeur), les témoignages des contributeurs
 * survivent à un enregistrement fait avec une page ancienne, un contributeur retiré perd son lien, et l'invité ne
 * voit ni l'e-mail du propriétaire ni le lien de paiement. Avant le 30/09/2026, un lien de partage permettait de
 * réécrire le titre et le texte final, et l'enregistrement du propriétaire effaçait les témoignages arrivés entre-temps.
 */
class ChapterGuestAccessTest extends BookTypeApiTestCase
{
    private string $bookId;
    private string $chapterId;
    private array $lea;
    private array $marc;

    protected function setUp(): void
    {
        parent::setUp();
        [, $book] = $this->api('POST', '/books', ['title' => 'Famille de test', 'type' => 'famille'], 'client');
        $this->bookId = $book['id'];
        [, $chapter] = $this->api('POST', "/books/{$this->bookId}/chapters", ['title' => 'Paroles d\'enfants', 'theme' => 'regards_croises', 'position' => 2, 'answers' => []], 'client');
        $this->chapterId = $chapter['id'];
        [, $this->lea] = $this->api('POST', "/books/{$this->bookId}/contributors", ['firstName' => 'Léa', 'role' => 'enfant'], 'client');
        [, $this->marc] = $this->api('POST', "/books/{$this->bookId}/contributors", ['firstName' => 'Marc', 'role' => 'enfant'], 'client');
    }

    public function testAGuestOnlyEditsAnswers(): void
    {
        self::db()->prepare("UPDATE mv_chapter SET content_final = 'Texte du propriétaire.' WHERE id = ?")->execute([$this->chapterId]);

        [$status] = $this->api('PUT', "/chapters/{$this->chapterId}?" . $this->signed(), [
            'title' => 'Titre réécrit', 'contentFinal' => 'Texte réécrit.', 'position' => 9, 'theme' => 'autre',
            'answers' => [['index' => 0, 'question' => 'Un souvenir ?', 'answer' => 'Réponse de l\'invité']],
        ], null);

        $this->assertSame(200, $status);
        $row = self::db()->query("SELECT title, content_final, position, theme, answers FROM mv_chapter WHERE id = '{$this->chapterId}'")->fetch();
        $this->assertSame(['Paroles d\'enfants', 2, 'regards_croises'], [$row['title'], $row['position'], $row['theme']], 'titre, position et thème restent au propriétaire');
        $this->assertSame('Texte réécrit.', $row['content_final'], 'le lien de partage du chapitre sert à relire le texte (« Partager l\'édition »)');
        $this->assertStringContainsString('invit', $row['answers'], 'la réponse de l\'invité est enregistrée');

        // Le lien personnel d'un contributeur ne touche pas au texte final
        [$status] = $this->api('PUT', "/chapters/{$this->chapterId}?" . $this->signed($this->lea['id']), ['contentFinal' => 'Réécrit par Léa.'], null);
        $this->assertSame(200, $status);
        $this->assertSame('Texte réécrit.', self::db()->query("SELECT content_final FROM mv_chapter WHERE id = '{$this->chapterId}'")->fetchColumn());

        // Le propriétaire, lui, modifie tout
        [$status] = $this->api('PUT', "/chapters/{$this->chapterId}", ['title' => 'Nouveau titre', 'contentFinal' => 'Texte relu.'], 'client');
        $this->assertSame(200, $status);
        $this->assertSame('Nouveau titre', self::db()->query("SELECT title FROM mv_chapter WHERE id = '{$this->chapterId}'")->fetchColumn());
    }

    public function testContributorTestimoniesSurviveAStaleSave(): void
    {
        $this->contribute($this->lea, 'Réponse de Léa');
        [, $ownerPage] = $this->api('GET', "/chapters/{$this->chapterId}", null, 'client');
        $this->contribute($this->marc, 'Réponse de Marc');

        // Le propriétaire enregistre la page ouverte avant la réponse de Marc
        [$status] = $this->api('PUT', "/chapters/{$this->chapterId}", ['contributorAnswers' => $ownerPage['contributorAnswers']], 'client');
        $this->assertSame(200, $status);
        $this->assertEqualsCanonicalizing(['Léa', 'Marc'], $this->contributorNames());

        // Un lien de partage simple ne peut pas non plus vider les témoignages
        $this->api('PUT', "/chapters/{$this->chapterId}?" . $this->signed(), ['contributorAnswers' => []], null);
        $this->assertEqualsCanonicalizing(['Léa', 'Marc'], $this->contributorNames());

        // Marc ne peut écrire que son propre témoignage
        $this->api('PUT', "/chapters/{$this->chapterId}?" . $this->signed($this->marc['id']), ['contributorAnswers' => [$this->entry($this->lea, 'Écrasé par Marc')]], null);
        $this->assertStringNotContainsString('Écrasé', (string) self::db()->query("SELECT contributor_answers FROM mv_chapter WHERE id = '{$this->chapterId}'")->fetchColumn());
        $this->assertEqualsCanonicalizing(['Léa', 'Marc'], $this->contributorNames());
    }

    public function testTheChapterShareLinkCanOpenEachContributorInTurn(): void
    {
        // Écran « un onglet par contributeur » ouvert par le lien de partage du chapitre (sans contributeur signé)
        $this->contribute($this->lea, 'Réponse de Léa');
        $this->contribute($this->marc, 'Réponse de Marc');
        $this->client->getHistory()->clear();
        $link = $this->signed();

        [$status, $asMarc] = $this->api('GET', "/chapters/{$this->chapterId}?$link&contributorId={$this->marc['id']}", null, null);
        $this->assertSame(200, $status);
        $this->assertSame($this->marc['id'], $asMarc['currentContributor']['id']);
        $this->assertSame(['Marc'], array_column($asMarc['contributorAnswers'], 'contributorName'), 'seul le témoignage du contributeur demandé');

        // L'enregistrement fait pour Marc ne touche pas à Léa
        [$status] = $this->api('PUT', "/chapters/{$this->chapterId}?$link&contributorId={$this->marc['id']}", ['contributorAnswers' => [$this->entry($this->marc, 'Marc, corrigé')]], null);
        $this->assertSame(200, $status);
        $stored = (string) self::db()->query("SELECT contributor_answers FROM mv_chapter WHERE id = '{$this->chapterId}'")->fetchColumn();
        $this->assertStringContainsString('Marc, corrig', $stored);
        $this->assertStringContainsString('ponse de L', $stored);

        // Un identifiant qui n'est pas un contributeur du livre : le lien reste un simple lien de partage
        [$status, $unknown] = $this->api('GET', "/chapters/{$this->chapterId}?$link&contributorId=00000000-0000-4000-8000-000000000000", null, null);
        $this->assertSame(200, $status);
        $this->assertNull($unknown['currentContributor']);
    }

    public function testARemovedContributorLosesTheirLink(): void
    {
        $link = $this->signed($this->marc['id']);
        [$status] = $this->api('GET', "/chapters/{$this->chapterId}?$link", null, null);
        $this->assertSame(200, $status);

        $this->api('DELETE', "/contributors/{$this->marc['id']}", null, 'client');

        [$status] = $this->api('GET', "/chapters/{$this->chapterId}?$link", null, null);
        $this->assertSame(401, $status, 'lien du chapitre');
        [$status] = $this->api('GET', "/books/{$this->bookId}?$link", null, null);
        $this->assertSame(401, $status, 'lien du livre');
        [$status] = $this->api('PUT', "/chapters/{$this->chapterId}?$link", ['contributorAnswers' => [$this->entry($this->marc, 'Après mon retrait')]], null);
        $this->assertSame(401, $status);
    }

    public function testAGuestDoesNotSeeTheOwnersPrivateDetails(): void
    {
        self::db()->prepare("UPDATE mv_book SET payment_link_url = 'https://checkout.example/lien' WHERE id = ?")->execute([$this->bookId]);

        [$status, $guest] = $this->api('GET', "/books/{$this->bookId}?" . $this->signed($this->lea['id']), null, null);
        $this->assertSame(200, $status);
        $this->assertNull($guest['author']['email']);
        $this->assertNull($guest['paymentLinkUrl']);
        $this->assertNotEmpty($guest['author']['firstName']);

        [, $owner] = $this->api('GET', "/books/{$this->bookId}", null, 'client');
        $this->assertSame(self::CLIENT_EMAIL, $owner['author']['email']);
        $this->assertSame('https://checkout.example/lien', $owner['paymentLinkUrl']);
    }

    private function contribute(array $contributor, string $text): void
    {
        [$status] = $this->api('PUT', "/chapters/{$this->chapterId}?" . $this->signed($contributor['id']), ['contributorAnswers' => [$this->entry($contributor, $text)]], null);
        $this->assertSame(200, $status);
    }

    private function entry(array $contributor, string $text): array
    {
        return ['id' => $contributor['id'], 'contributorName' => $contributor['firstName'], 'answers' => [['index' => 0, 'question' => 'Un souvenir ?', 'answer' => $text]]];
    }

    /** @return string[] */
    private function contributorNames(): array
    {
        $raw = self::db()->query("SELECT contributor_answers FROM mv_chapter WHERE id = '{$this->chapterId}'")->fetchColumn();

        return array_column(json_decode((string) $raw, true) ?: [], 'contributorName');
    }

    private function signed(?string $contributorId = null): string
    {
        $expires = time() + 600;
        $secret = static::getContainer()->getParameter('kernel.secret');
        $data = $contributorId === null ? "chapterId={$this->chapterId}&expires=$expires" : "chapterId={$this->chapterId}&contributorId=$contributorId&expires=$expires";

        return http_build_query(array_filter(['chapterId' => $this->chapterId, 'contributorId' => $contributorId, 'expires' => $expires, 'signature' => hash_hmac('sha256', $data, $secret)]));
    }
}
