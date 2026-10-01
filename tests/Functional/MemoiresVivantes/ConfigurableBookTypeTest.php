<?php

namespace App\Tests\Functional\MemoiresVivantes;

/**
 * Livres d'un type configurable (créé dans la console, consignes en base) vus par le frontend : le livre porte les
 * propriétés de son type même désactivé, un contributeur ne reçoit qu'un rôle du type, et la page du propriétaire,
 * qui ne voit les réponses des contributeurs que pour un rôle, ne les efface pas en enregistrant.
 */
class ConfigurableBookTypeTest extends BookTypeApiTestCase
{
    private array $type;

    protected function setUp(): void
    {
        parent::setUp();
        $code = self::uniqueCode('entreprise');
        [$status, $created] = $this->api('POST', '/admin/book-types', ['code' => $code, 'label' => 'Livre d\'entreprise', 'family' => 'collectif']);
        $this->assertSame(201, $status, json_encode($created));
        $id = $created['bookType']['id'];
        $this->api('POST', "/admin/book-types/$id/roles", ['code' => 'fondateur', 'label' => 'Fondateur']);
        $this->api('POST', "/admin/book-types/$id/roles", ['code' => 'collegue', 'label' => 'Collègue']);
        [, $chapter] = $this->api('POST', "/admin/book-types/$id/chapters", ['code' => 'debuts', 'title' => 'Les débuts', 'speaker' => 'contributors']);
        $chapterId = $this->chapter($chapter['bookType'], 'debuts')['id'];
        $this->api('POST', "/admin/book-type-chapters/$chapterId/questions", ['questionText' => 'Comment tout a commencé ?', 'role' => 'fondateur']);
        $this->api('POST', "/admin/book-type-chapters/$chapterId/questions", ['questionText' => 'Votre premier jour ?', 'role' => 'collegue']);
        $this->api('POST', "/admin/book-types/$id/chapters", ['code' => 'synthese', 'title' => 'Synthèse', 'speaker' => 'synthesis']);
        [$status, $updated] = $this->api('PUT', "/admin/book-types/$id", ['promptRaw' => 'Raconte l\'histoire de {titre_livre}.', 'defaultRole' => 'fondateur', 'isActive' => true]);
        $this->assertSame(200, $status, json_encode($updated));
        $this->type = $updated['bookType'];
    }

    public function testTheBookCarriesItsTypeEvenOnceDeactivated(): void
    {
        [$status, $book] = $this->api('POST', '/books', ['title' => 'Notre maison', 'type' => $this->type['code']], 'client');
        $this->assertSame(201, $status);
        $this->assertSame($this->type['code'], $book['type']);
        $this->assertTrue($book['typeInfo']['isActive']);
        $this->assertSame(['debuts', 'synthese'], array_column($book['typeInfo']['chapters'], 'code'));
        $this->assertEqualsCanonicalizing(['fondateur', 'collegue'], array_column($book['typeInfo']['roles'], 'code'));
        $this->assertSame(['collectif', 'fondateur'], [$book['typeInfo']['family'], $book['typeInfo']['defaultRole']]);

        $this->api('PUT', '/admin/book-types/' . $this->type['id'], ['isActive' => false]);

        [, $types] = $this->api('GET', '/book-types', null, null);
        $this->assertNotContains($this->type['code'], array_column($types, 'code'), 'type désactivé : plus proposé à la création');
        [$status, $reloaded] = $this->api('GET', "/books/{$book['id']}", null, 'client');
        $this->assertSame(200, $status);
        $this->assertFalse($reloaded['typeInfo']['isActive']);
        $this->assertSame(['debuts', 'synthese'], array_column($reloaded['typeInfo']['chapters'], 'code'), 'le livre existant garde ses chapitres et ses rôles');
        [, $list] = $this->api('GET', '/books', null, 'client');
        $mine = array_values(array_filter($list, fn ($b) => $b['id'] === $book['id']))[0];
        $this->assertSame($this->type['code'], $mine['typeInfo']['code']);

        // Les types d'origine portent aussi leurs propriétés
        [, $legacy] = $this->api('POST', '/books', ['title' => 'Famille', 'type' => 'famille'], 'client');
        $this->assertSame(['famille', true], [$legacy['typeInfo']['code'], $legacy['typeInfo']['isActive']]);
    }

    public function testAContributorRoleMustBelongToTheConfigurableType(): void
    {
        [, $book] = $this->api('POST', '/books', ['title' => 'Notre maison', 'type' => $this->type['code']], 'client');

        [$status, $body] = $this->api('POST', "/books/{$book['id']}/contributors", ['firstName' => 'Léa', 'role' => 'stagiaire'], 'client');
        $this->assertSame(422, $status);
        $this->assertEqualsCanonicalizing(['fondateur', 'collegue'], $body['allowedRoles']);
        $this->assertStringContainsString('fondateur', $body['error']);

        [$status, $contributor] = $this->api('POST', "/books/{$book['id']}/contributors", ['firstName' => 'Léa', 'role' => 'collegue'], 'client');
        $this->assertSame(201, $status);
        [$status] = $this->api('PUT', "/contributors/{$contributor['id']}", ['role' => 'inconnu'], 'client');
        $this->assertSame(422, $status);
        [$status] = $this->api('PUT', "/contributors/{$contributor['id']}", ['role' => 'fondateur'], 'client');
        $this->assertSame(200, $status);

        // Types d'origine : rôles libres, comme avant
        [, $family] = $this->api('POST', '/books', ['title' => 'Famille', 'type' => 'famille'], 'client');
        [$status] = $this->api('POST', "/books/{$family['id']}/contributors", ['firstName' => 'Marc', 'role' => 'cousin_eloigne'], 'client');
        $this->assertSame(201, $status);
    }

    public function testSavingAFilteredViewKeepsTheHiddenAnswers(): void
    {
        [, $book] = $this->api('POST', '/books', ['title' => 'Notre maison', 'type' => $this->type['code']], 'client');
        [, $chapter] = $this->api('POST', "/books/{$book['id']}/chapters", ['title' => 'Les débuts', 'theme' => 'debuts', 'position' => 1, 'answers' => []], 'client');
        [, $lea] = $this->api('POST', "/books/{$book['id']}/contributors", ['firstName' => 'Léa', 'role' => 'collegue'], 'client');
        $stored = fn () => json_decode((string) self::db()->query("SELECT contributor_answers FROM mv_chapter WHERE id = '{$chapter['id']}'")->fetchColumn(), true);

        $expires = time() + 600;
        $link = http_build_query(['chapterId' => $chapter['id'], 'contributorId' => $lea['id'], 'expires' => $expires,
            'signature' => hash_hmac('sha256', "chapterId={$chapter['id']}&contributorId={$lea['id']}&expires=$expires", static::getContainer()->getParameter('kernel.secret'))]);
        $this->api('PUT', "/chapters/{$chapter['id']}?$link", ['contributorAnswers' => [['id' => $lea['id'], 'contributorName' => 'Léa',
            'answers' => [['index' => 0, 'question' => 'Votre premier jour ?', 'answer' => 'Un lundi de pluie.']]]]], null);

        // Nouvelle navigation : le client de test enverrait sinon le lien signé de Léa comme page d'origine (Referer)
        $this->client->getHistory()->clear();

        // Le propriétaire (rôle par défaut : fondateur) ne reçoit pas les réponses aux questions du rôle « collègue »
        [, $ownerView] = $this->api('GET', "/chapters/{$chapter['id']}", null, 'client');
        $this->assertSame(['Comment tout a commencé ?'], array_column($ownerView['questions'], 'question'));
        $this->assertSame([], $ownerView['contributorAnswers'][0]['answers'], 'réponse d\'un autre rôle masquée dans cette vue');

        // …et l'enregistrement de cette vue ne l'efface pas
        [$status] = $this->api('PUT', "/chapters/{$chapter['id']}", ['contributorAnswers' => $ownerView['contributorAnswers']], 'client');
        $this->assertSame(200, $status);
        $this->assertSame('Un lundi de pluie.', $stored()[0]['answers'][0]['answer']);

        // En demandant le contributeur, on voit ses réponses ; une réponse envoyée vide est bien effacée
        [, $leaView] = $this->api('GET', "/chapters/{$chapter['id']}?contributorId={$lea['id']}", null, 'client');
        $this->assertSame('Un lundi de pluie.', $leaView['contributorAnswers'][0]['answers'][0]['answer']);
        $this->api('PUT', "/chapters/{$chapter['id']}", ['contributorAnswers' => [['id' => $lea['id'], 'contributorName' => 'Léa',
            'answers' => [['index' => 0, 'question' => 'Votre premier jour ?', 'answer' => '']]]]], 'client');
        $this->assertSame('', $stored()[0]['answers'][0]['answer']);
        $this->assertCount(1, $stored()[0]['answers']);
    }
}
