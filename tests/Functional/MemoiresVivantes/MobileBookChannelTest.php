<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;

/**
 * Livres de l'application mobile Living Memory (16/10/2026) : POST /mobile/books crée un livre channel = mobile,
 * propriété de l'utilisateur connecté ; les livres du web restent channel = web ; l'origine ne change jamais ; un type
 * inconnu ou inactif est refusé sur la route mobile seulement ; l'administration par le web voit ces livres comme les
 * autres ; l'auteur du PDF d'un livre mobile n'est jamais le nom par défaut du site.
 */
class MobileBookChannelTest extends BookTypeApiTestCase
{
    public function testABookCreatedFromTheAppIsMarkedMobileAndOwnedByTheUser(): void
    {
        [$status, $book] = $this->api('POST', '/mobile/books', ['title' => 'Mon livre mobile', 'type' => 'individuel', 'person1FirstName' => 'Jeanne'], 'client');
        $this->assertSame(201, $status, json_encode($book));
        $this->assertSame('mobile', $book['channel']);
        $this->assertSame(self::CLIENT_EMAIL, $book['author']['email'], 'propriétaire = l\'utilisateur connecté');
        $this->assertSame(['individuel', 'Jeanne', 'unpaid'], [$book['type'], $book['person1FirstName'], $book['paymentStatus']]);
        $this->assertArrayHasKey('typeInfo', $book, 'même réponse que POST /books');

        [$status, $web] = $this->api('POST', '/books', ['title' => 'Livre web', 'type' => 'individuel'], 'client');
        $this->assertSame(201, $status);
        $this->assertSame('web', $web['channel'], 'la route du web ne change pas');

        // L'origine ne se modifie jamais, même envoyée dans le corps
        [$status, $updated] = $this->api('PUT', "/books/{$book['id']}", ['title' => 'Renommé', 'channel' => 'web'], 'client');
        $this->assertSame(200, $status);
        $this->assertSame(['Renommé', 'mobile'], [$updated['title'], $updated['channel']]);
        [, $webUpdated] = $this->api('PUT', "/books/{$web['id']}", ['channel' => 'mobile'], 'client');
        $this->assertSame('web', $webUpdated['channel']);
        $this->assertSame(['mobile', 'web'], array_column(self::db()->query("SELECT channel FROM mv_book WHERE id IN ('{$book['id']}', '{$web['id']}') ORDER BY channel")->fetchAll(), 'channel'));

        // Lecture : la liste et le détail renvoient l'origine
        [, $mine] = $this->api('GET', '/books?scope=my', null, 'client');
        $byId = array_column($mine, null, 'id');
        $this->assertSame(['mobile', 'web'], [$byId[$book['id']]['channel'], $byId[$web['id']]['channel']]);
        [, $one] = $this->api('GET', "/books/{$book['id']}", null, 'client');
        $this->assertSame('mobile', $one['channel']);

        // Anonyme et titre manquant
        [$status] = $this->api('POST', '/mobile/books', ['title' => 'Anonyme', 'type' => 'individuel'], null);
        $this->assertSame(401, $status, 'connexion obligatoire');
        [$status] = $this->api('POST', '/mobile/books', ['type' => 'individuel'], 'client');
        $this->assertSame(422, $status, 'titre obligatoire, comme sur le web');
    }

    public function testAnUnknownOrInactiveTypeIsRefusedOnTheMobileRouteOnly(): void
    {
        [$status, $r] = $this->api('POST', '/mobile/books', ['title' => 'Type inconnu', 'type' => 'type_inexistant_xyz'], 'client');
        $this->assertSame(422, $status);
        $this->assertSame('TYPE_INVALID', $r['error']);
        $this->assertStringContainsString('type_inexistant_xyz', $r['message']);
        [$status, $r] = $this->api('POST', '/mobile/books', ['title' => 'Type mal formé', 'type' => 'Pas Un Code'], 'client');
        $this->assertSame([422, 'TYPE_INVALID'], [$status, $r['error']]);

        // Type configurable désactivé
        $code = self::uniqueCode('mob');
        [$status, $created] = $this->api('POST', '/admin/book-types', ['code' => $code, 'label' => 'Type mobile', 'family' => 'collectif']);
        $this->assertSame(201, $status, json_encode($created));
        [$status, $r] = $this->api('POST', '/mobile/books', ['title' => 'Type pas encore activé', 'type' => $code], 'client');
        $this->assertSame([422, 'TYPE_INVALID'], [$status, $r['error']], 'un type configurable naît inactif');
        [$status, $r] = $this->api('PUT', '/admin/book-types/' . $created['bookType']['id'], ['promptRaw' => 'Raconte l\'histoire de {titre_livre}.', 'isActive' => true]);
        $this->assertSame(200, $status, json_encode($r));
        [$status, $active] = $this->api('POST', '/mobile/books', ['title' => 'Type actif', 'type' => $code], 'client');
        $this->assertSame(201, $status, json_encode($active));
        $this->assertSame([$code, 'mobile'], [$active['type'], $active['channel']]);
        [$status] = $this->api('PUT', '/admin/book-types/' . $created['bookType']['id'], ['isActive' => false]);
        $this->assertSame(200, $status);
        [$status, $r] = $this->api('POST', '/mobile/books', ['title' => 'Type inactif', 'type' => $code], 'client');
        $this->assertSame([422, 'TYPE_INVALID'], [$status, $r['error']]);

        // Le web garde son repli : livre individuel
        [$status, $web] = $this->api('POST', '/books', ['title' => 'Web type inconnu', 'type' => 'type_inexistant_xyz'], 'client');
        $this->assertSame(201, $status);
        $this->assertSame(['individuel', 'web'], [$web['type'], $web['channel']]);

        // Sans type : individuel, comme sur le web
        [$status, $noType] = $this->api('POST', '/mobile/books', ['title' => 'Sans type'], 'client');
        $this->assertSame(201, $status);
        $this->assertSame(['individuel', 'mobile'], [$noType['type'], $noType['channel']]);
    }

    public function testTheSiteAdminSeesAndOpensMobileBooksLikeAnyOther(): void
    {
        [$status, $book] = $this->api('POST', '/mobile/books', ['title' => 'Livre mobile à administrer', 'type' => 'famille'], 'client');
        $this->assertSame(201, $status, json_encode($book));

        [$status, $all] = $this->api('GET', '/books?scope=all', null, 'admin');
        $this->assertSame(200, $status);
        $byId = array_column($all, null, 'id');
        $this->assertArrayHasKey($book['id'], $byId, 'listé avec tous les livres du site');
        $this->assertSame('mobile', $byId[$book['id']]['channel']);
        [, $filtered] = $this->api('GET', '/books?scope=all&userId=' . $book['author']['id'], null, 'admin');
        $this->assertContains($book['id'], array_column($filtered, 'id'), 'filtre par utilisateur');

        [$status, $opened] = $this->api('GET', "/books/{$book['id']}", null, 'admin');
        $this->assertSame(200, $status);
        $this->assertSame(['mobile', self::CLIENT_EMAIL], [$opened['channel'], $opened['author']['email']]);
        [$status, $updated] = $this->api('PUT', "/books/{$book['id']}", ['subtitle' => 'Sous-titre admin'], 'admin');
        $this->assertSame(200, $status);
        $this->assertSame(['Sous-titre admin', 'mobile'], [$updated['subtitle'], $updated['channel']]);
        [$status, $chapter] = $this->api('POST', "/books/{$book['id']}/chapters", ['title' => 'Paroles', 'theme' => 'regards_croises', 'position' => 2, 'answers' => []], 'admin');
        $this->assertSame(201, $status, json_encode($chapter));
        [$status, $chapters] = $this->api('GET', "/books/{$book['id']}/chapters", null, 'admin');
        $this->assertSame(200, $status);
        $this->assertContains($chapter['id'], array_column($chapters, 'id'));
        [$status] = $this->api('DELETE', "/books/{$book['id']}", null, 'admin');
        $this->assertSame(200, $status);
    }

    public function testThePdfAuthorOfAMobileBookIsNeverTheHardCodedDefault(): void
    {
        $service = static::getContainer()->get(BookPdfGeneratorService::class);
        $owner = (new User())->setFirstname('Jeanne')->setLastname('Dupont');
        $nameless = (new User())->setFirstname('')->setLastname('');

        $mobile = (new Book())->setTitle('t')->setUser($owner)->setChannel(Book::CHANNEL_MOBILE);
        $this->assertSame('Jeanne Dupont', $service->resolveAuthorName($mobile), 'à défaut d\'autre nom : le propriétaire');
        $this->assertSame('Nom choisi', $service->resolveAuthorName($mobile, 'Nom choisi'));
        $this->assertSame('Marie', $service->resolveAuthorName($mobile->setPerson1FirstName('Marie')), 'le narrateur d\'abord, comme sur le web');
        $this->assertSame('', $service->resolveAuthorName((new Book())->setTitle('t')->setUser($nameless)->setChannel(Book::CHANNEL_MOBILE)), 'sans aucun nom : vide, jamais le nom par défaut');

        $this->assertSame('Danielle Almont', $service->resolveAuthorName((new Book())->setTitle('t')->setUser($nameless)), 'livre web : inchangé');
        $this->assertSame('web', (new Book())->getChannel(), 'origine par défaut');
        $this->expectException(\InvalidArgumentException::class);
        (new Book())->setChannel('tablette');
    }
}
