<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Message\GenerateBookPdfHandler;
use App\MemoiresVivantes\Message\GenerateBookPdfMessage;
use App\MemoiresVivantes\Services\BookPdfGenerationService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Mise en page PDF en tâche de fond (lot 3, 03/11/2026). Sans `async=1`, POST pdf/generate garde sa réponse historique (200, fichiers
 * prêts). Avec `async=1` : 202 et état pending, un seul message dans la file `pdf`, aucun second message tant que la génération est en
 * cours, état `done` avec URL, nombre de pages et géométrie une fois le gestionnaire passé ; génération perdue passée en `failed` et
 * relançable ; droits inchangés (401 anonyme, 403 intrus) sur pdf/generate et pdf/status.
 */
class BookPdfAsyncTest extends WebTestCase
{
    private KernelBrowser $client;
    private TenantEntityManagerProvider $provider;
    private string $bookId = '';
    private string $ownerJwt;
    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // même conteneur entre deux requêtes : le transport en mémoire garde ses messages
        $this->provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $this->provider->getEntityManager();
        $owner = $this->user('proprietaire');
        $book = (new Book())->setTitle('Le livre de la famille')->setPerson1FirstName('Richard')->setPerson2FirstName('Émilie')->setUser($owner);
        $chapter = (new Chapter())->setTitle('Chapitre 1 — Nos racines')->setTheme('histoire_parents')->setPosition(1)
            ->setContentFinal("===Ce que la terre garde===\n\nIl est des histoires qui commencent bien avant que leurs protagonistes ne se rencontrent.\n\nEt la suite de l'histoire, pour remplir une page.");
        $book->addChapter($chapter);
        $em->persist($book);
        $em->persist($chapter);
        $this->projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $em->flush();
        $this->bookId = $book->getId()->toRfc4122();
        $this->ownerJwt = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($owner);
    }

    public function testWithoutTheAsyncFlagTheHistoricalSynchronousResponseIsUnchanged(): void
    {
        $response = $this->call('POST', "/books/{$this->bookId}/pdf/generate", $this->ownerJwt, ['cover_style' => 'biographic_split']);
        $this->assertSame(200, $response['status'], json_encode($response['body']));
        $this->assertSame(['success', 'book_id', 'interior_url', 'cover_url', 'page_count', 'cover_geometry'], array_keys($response['body']), 'mêmes clés qu\'avant');
        $this->assertTrue($response['body']['success']);
        $this->assertSame("/uploads/memoires/books/{$this->bookId}/interior.pdf", $response['body']['interior_url']);
        $this->assertGreaterThanOrEqual(24, $response['body']['page_count']);
        $this->assertFileExists($this->bookDir() . '/interior.pdf');
        $this->assertFileExists($this->bookDir() . '/cover.pdf');
        $this->assertNull($this->db()->fetchOne('SELECT pdf_status FROM mv_book WHERE id = ?', [$this->bookId]), 'le rendu synchrone ne touche pas au suivi');
        $this->assertCount(0, $this->transport()->getSent(), 'rien dans la file');
    }

    public function testWithAsyncTheRequestQueuesOnceAndTheWorkerProducesTheFiles(): void
    {
        $response = $this->call('POST', "/books/{$this->bookId}/pdf/generate", $this->ownerJwt, ['async' => true, 'cover_style' => 'biographic_split', 'bg_color' => '#336699']);
        $this->assertSame(202, $response['status'], json_encode($response['body']));
        $body = $response['body'];
        $this->assertTrue($body['success']);
        $this->assertTrue($body['async']);
        $this->assertSame('pending', $body['status']);
        $this->assertFalse($body['alreadyInProgress']);
        $this->assertFalse($body['ready']);
        $this->assertNull($body['interior_url'], 'aucune URL tant que le fichier n\'est pas prêt');
        $this->assertNull($body['page_count'], 'aucune valeur inventée');
        $this->assertNull($body['cover_geometry']);
        $this->assertNotNull($body['requested_at']);
        $this->assertSame('pending', $this->db()->fetchOne('SELECT pdf_status FROM mv_book WHERE id = ?', [$this->bookId]));
        $sent = $this->transport()->getSent();
        $this->assertCount(1, $sent, 'un message dans la file pdf');
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(GenerateBookPdfMessage::class, $message);
        $this->assertSame([$this->bookId, MV_TEST_TENANT_CODE, 'biographic_split', '#336699'], [$message->bookId, $message->tenantCode, $message->coverStyle, $message->bgColor]);

        // État avant le worker, puis seconde demande : aucune nouvelle mise en file
        $status = $this->call('GET', "/books/{$this->bookId}/pdf/status", $this->ownerJwt);
        $this->assertSame([200, 'pending', false], [$status['status'], $status['body']['status'], $status['body']['ready']]);
        $again = $this->call('POST', "/books/{$this->bookId}/pdf/generate", $this->ownerJwt, ['async' => '1']);
        $this->assertSame(202, $again['status']);
        $this->assertTrue($again['body']['alreadyInProgress'], 'génération déjà en cours : pas de doublon');
        // Le transport en mémoire est remis à zéro au début de chaque requête : zéro = aucun message envoyé par cette seconde demande
        $this->assertCount(0, $this->transport()->getSent(), 'aucun nouveau message');

        // Le worker traite le message
        static::getContainer()->get(GenerateBookPdfHandler::class)($message);
        $row = $this->db()->fetchAssociative('SELECT pdf_status, pdf_page_count, pdf_generated_at, pdf_error FROM mv_book WHERE id = ?', [$this->bookId]);
        $this->assertSame('done', $row['pdf_status'], json_encode($row));
        $this->assertGreaterThanOrEqual(24, (int) $row['pdf_page_count']);
        $this->assertSame(0, ((int) $row['pdf_page_count']) % 2, 'nombre de pages pair');
        $this->assertNotNull($row['pdf_generated_at']);
        $this->assertNull($row['pdf_error']);
        $this->assertFileExists($this->bookDir() . '/interior.pdf');
        $this->assertFileExists($this->bookDir() . '/cover.pdf');

        $done = $this->call('GET', "/books/{$this->bookId}/pdf/status", $this->ownerJwt);
        $this->assertSame(200, $done['status']);
        $this->assertSame('done', $done['body']['status']);
        $this->assertTrue($done['body']['ready']);
        $this->assertSame("/uploads/memoires/books/{$this->bookId}/interior.pdf", $done['body']['interior_url']);
        $this->assertSame("/uploads/memoires/books/{$this->bookId}/cover.pdf", $done['body']['cover_url']);
        $this->assertSame((int) $row['pdf_page_count'], $done['body']['page_count']);
        $this->assertSame((int) $row['pdf_page_count'], $done['body']['cover_geometry']['page_count']);
        $this->assertNull($done['body']['error']);

        // La fiche du livre reprend l'état
        $book = $this->call('GET', "/books/{$this->bookId}", $this->ownerJwt);
        $this->assertSame(200, $book['status']);
        $this->assertSame('done', $book['body']['pdfStatus'] ?? $book['body']['pdf_status'] ?? null);
        $this->assertSame((int) $row['pdf_page_count'], $book['body']['pdfPageCount'] ?? $book['body']['pdf_page_count'] ?? null);

        // Nouvelle demande après done : remise en file
        $third = $this->call('POST', "/books/{$this->bookId}/pdf/generate", $this->ownerJwt, ['async' => 1]);
        $this->assertSame([202, 'pending', false], [$third['status'], $third['body']['status'], $third['body']['alreadyInProgress']]);
        $this->assertCount(1, $this->transport()->getSent(), 'un message pour cette nouvelle demande');
    }

    public function testALostGenerationIsMarkedFailedAndCanBeRequestedAgain(): void
    {
        $this->db()->executeStatement("UPDATE mv_book SET pdf_status = 'generating', pdf_requested_at = NOW() - INTERVAL '20 minutes' WHERE id = ?", [$this->bookId]);
        $status = $this->call('GET', "/books/{$this->bookId}/pdf/status", $this->ownerJwt);
        $this->assertSame(200, $status['status']);
        $this->assertSame('failed', $status['body']['status'], 'sans progression depuis 20 minutes : perdue');
        $this->assertStringContainsString('interrompue', (string) $status['body']['error']);
        $this->assertSame('failed', $this->db()->fetchOne('SELECT pdf_status FROM mv_book WHERE id = ?', [$this->bookId]));

        $retry = $this->call('POST', "/books/{$this->bookId}/pdf/generate", $this->ownerJwt, ['async' => true]);
        $this->assertSame([202, 'pending', false], [$retry['status'], $retry['body']['status'], $retry['body']['alreadyInProgress']]);
        $this->assertNull($retry['body']['error']);
        $this->assertCount(1, $this->transport()->getSent());

        // Un message pour un livre inconnu ou un site inconnu est ignoré sans erreur
        $handler = static::getContainer()->get(GenerateBookPdfHandler::class);
        $handler(new GenerateBookPdfMessage('00000000-0000-4000-8000-000000000000', MV_TEST_TENANT_CODE));
        $handler(new GenerateBookPdfMessage($this->bookId, 'site-inexistant'));
        $this->assertSame('pending', $this->db()->fetchOne('SELECT pdf_status FROM mv_book WHERE id = ?', [$this->bookId]), 'livre inchangé');
    }

    public function testRightsAreUnchangedOnBothRoutes(): void
    {
        $this->assertSame(401, $this->call('GET', "/books/{$this->bookId}/pdf/status", null)['status'], 'anonyme : statut');
        $this->assertSame(401, $this->call('POST', "/books/{$this->bookId}/pdf/generate", null, ['async' => true])['status'], 'anonyme : génération');
        $intruder = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user('intrus'));
        $this->assertContains($this->call('GET', "/books/{$this->bookId}/pdf/status", $intruder)['status'], [401, 403], 'intrus : statut refusé (même règle que la lecture du livre)');
        $this->assertSame(403, $this->call('POST', "/books/{$this->bookId}/pdf/generate", $intruder, ['async' => true])['status'], 'intrus : génération');
        $this->assertNull($this->db()->fetchOne('SELECT pdf_status FROM mv_book WHERE id = ?', [$this->bookId]), 'rien mis en file');
        $this->assertSame(404, $this->call('GET', '/books/00000000-0000-4000-8000-000000000000/pdf/status', $this->ownerJwt)['status']);
        $this->assertSame(BookPdfGenerationService::IN_PROGRESS, ['pending', 'generating']);
    }

    /** @return array{status: int, body: array} */
    private function call(string $method, string $path, ?string $jwt, ?array $body = null): array
    {
        $server = ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($jwt !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . '/api/memoires' . $path, [], [], $server, $body === null ? null : json_encode($body));
        $response = $this->client->getResponse();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true) ?? []];
    }

    private function user(string $prefix): User
    {
        $em = $this->provider->getEntityManager();
        $email = $prefix . '-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Pdf')->setLastname('Test')->setRoles(['ROLE_USER_INTERNET'])->setPassword('inutilisable')->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function transport(): \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport
    {
        return $this->client->getContainer()->get('messenger.transport.pdf');
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $this->provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $this->provider->getEntityManager()->getConnection();
    }

    private function bookDir(): string
    {
        return $this->projectDir . '/var/storage/public_bucket/uploads/memoires/books/' . $this->bookId;
    }

    /** Fichiers du livre de test supprimés sans toucher au noyau (le relancer ici casserait le test suivant) */
    protected function tearDown(): void
    {
        if ($this->bookId !== '' && $this->projectDir !== '') {
            foreach (glob($this->bookDir() . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->bookDir());
        }
        parent::tearDown();
    }
}
