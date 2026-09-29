<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;

/**
 * Accès aux livres (BookAccessGuard) : impression, génération des PDF et paiement réservés au propriétaire connecté
 * (ou à un admin) ; aperçus et statut de paiement : propriétaire ou lien de chapitre signé. Avant le 29/09/2026, ces
 * routes ne vérifiaient que l'existence du livre, et la commande d'impression acceptait une requête anonyme.
 */
class BookAccessTest extends BookTypeApiTestCase
{
    private string $bookId;
    private string $chapterId;

    protected function setUp(): void
    {
        parent::setUp();
        $row = self::db()->query('SELECT b.id AS book, c.id AS chapter FROM mv_book b JOIN mv_chapter c ON c.book_id = b.id ORDER BY b.id LIMIT 1')->fetch();
        [$this->bookId, $this->chapterId] = [$row['book'], $row['chapter']];
        $this->tokenFor('client');
        self::db()->prepare('UPDATE mv_book SET user_id = (SELECT id FROM "user" WHERE email = ?) WHERE id = ?')->execute([self::CLIENT_EMAIL, $this->bookId]);
    }

    public function testCostlyActionsRequireAConnection(): void
    {
        foreach ([
            ['POST', "/books/{$this->bookId}/print/order"],
            ['POST', '/print/order'],
            ['POST', "/books/{$this->bookId}/print/estimate"],
            ['POST', "/books/{$this->bookId}/print/payment-intent"],
            ['GET', "/books/{$this->bookId}/print/orders"],
            ['GET', '/print/orders/00000000-0000-4000-8000-000000000000'],
            ['POST', "/books/{$this->bookId}/pdf/generate"],
            ['POST', "/books/{$this->bookId}/payment-link"],
            ['POST', "/books/{$this->bookId}/send-payment-link"],
        ] as [$method, $path]) {
            [$status] = $this->api($method, $path, ['book_id' => $this->bookId], null);
            $this->assertSame(401, $status, "$method $path sans connexion");
        }
    }

    public function testAnotherUserCannotActOnTheBook(): void
    {
        $intruder = $this->intruderToken();
        foreach ([
            ['POST', "/books/{$this->bookId}/print/order"],
            ['POST', "/books/{$this->bookId}/print/estimate"],
            ['GET', "/books/{$this->bookId}/print/orders"],
            ['POST', "/books/{$this->bookId}/pdf/generate"],
            ['POST', "/books/{$this->bookId}/payment-link"],
        ] as [$method, $path]) {
            $this->assertSame(403, $this->call($method, $path, $intruder), "$method $path par un autre utilisateur");
        }
        foreach (["/books/{$this->bookId}/pdf/preview-cover", "/books/{$this->bookId}/payment-status"] as $path) {
            $this->assertContains($this->call('GET', $path, $intruder), [401, 403], "GET $path par un autre utilisateur");
        }
    }

    public function testOwnerCanListOrdersButOnlyPrintTheBookPdfs(): void
    {
        [$status, $orders] = $this->api('GET', "/books/{$this->bookId}/print/orders", null, 'client');
        $this->assertSame(200, $status);
        $this->assertIsArray($orders);

        [$status, $body] = $this->api('POST', "/books/{$this->bookId}/print/order", [
            'shipping_address' => ['name' => 'Client Test', 'street1' => '1 rue Test', 'city' => 'Montréal', 'postal_code' => 'H2X 1Y4', 'country_code' => 'CA', 'state_code' => 'QC', 'phone_number' => '5145550000', 'email' => 'client@example.invalid'],
            'custom_cover_pdf_url' => 'https://pirate.example/couverture.pdf',
            'custom_interior_pdf_url' => 'https://pirate.example/interieur.pdf',
        ], 'client');
        $this->assertSame(400, $status, json_encode($body));
        $this->assertStringContainsString('PDF refusé', $body['error'] ?? '');
        $this->assertSame(0, (int) self::db()->query("SELECT COUNT(*) FROM mv_book_print_order WHERE book_id = '{$this->bookId}'")->fetchColumn(), 'aucune commande créée');
    }

    public function testSignedChapterLinkStillOpensTheBook(): void
    {
        $expires = time() + 600;
        $secret = static::getContainer()->getParameter('kernel.secret');
        $query = http_build_query(['chapterId' => $this->chapterId, 'expires' => $expires, 'signature' => hash_hmac('sha256', "chapterId={$this->chapterId}&expires=$expires", $secret)]);

        [$status] = $this->api('GET', "/books/{$this->bookId}?$query", null, null);
        $this->assertSame(200, $status, 'lien signé valide');
        [$status] = $this->api('GET', "/books/{$this->bookId}/payment-status?$query", null, null);
        $this->assertNotSame(401, $status, 'statut de paiement avec un lien signé');

        [$status] = $this->api('GET', "/books/{$this->bookId}?" . str_replace('signature=', 'signature=0', $query), null, null);
        $this->assertSame(401, $status, 'signature fausse');
        [$status] = $this->api('POST', "/books/{$this->bookId}/pdf/generate?$query", null, null);
        $this->assertSame(401, $status, 'un lien signé ne suffit pas pour générer les PDF');
    }

    private function call(string $method, string $path, string $jwt): int
    {
        $this->client->request($method, "/api/memoires$path?locale=fr", [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test',
        ], json_encode(['book_id' => $this->bookId]));

        return $this->client->getResponse()->getStatusCode();
    }

    private function intruderToken(): string
    {
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $email = 'intrus-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Intrus')->setLastname('Test')
            ->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
