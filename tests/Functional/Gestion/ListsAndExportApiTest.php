<?php

namespace App\Tests\Functional\Gestion;

use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\FraisDePort;
use App\Entity\NoteDeFrais;
use App\Entity\Transporteur;
use App\Entity\TypeFournisseur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot G5 (10/11/2026, `docs/gestion-mise-a-niveau.md`) : filtres et pagination des collections et des fournisseurs (corps
 * inchangé, total dans les en-têtes), export comptable des achats en CSV, API Platform réduit à la suppression d'une commande.
 */
class ListsAndExportApiTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    public function testCollectionsAndSuppliersCanBeFilteredAndPaged(): void
    {
        $tag = bin2hex(random_bytes(2));
        $open = $this->create(Collections::class, ['nomCollection' => "Ouverte $tag", 'del' => false, 'isClosed' => false]);
        $closed = $this->create(Collections::class, ['nomCollection' => "Fermée $tag", 'del' => false, 'isClosed' => true]);
        $deleted = $this->create(Collections::class, ['nomCollection' => "Supprimée $tag", 'del' => true, 'isClosed' => false]);
        $this->endOfWrite();

        $ids = fn (array $list) => array_map('intval', array_column($list, 'id'));
        $default = $ids($this->get("/api/collections?q=$tag"));
        sort($default);
        $this->assertSame([$open->getId(), $closed->getId()], $default, 'défaut : ouvertes et fermées, hors supprimées');
        $this->assertSame([$open->getId()], $ids($this->get("/api/collections?q=$tag&status=open")));
        $this->assertSame([$closed->getId()], $ids($this->get("/api/collections?q=$tag&status=closed")));
        $this->assertSame([$deleted->getId()], $ids($this->get("/api/collections?q=$tag&status=deleted")));
        $allIds = $ids($this->get("/api/collections?q=$tag&status=all"));
        sort($allIds);
        $this->assertSame([$open->getId(), $closed->getId(), $deleted->getId()], $allIds);
        $this->assertNull($this->client->getResponse()->headers->get('X-Total-Count'), 'sans page/limit : pas d\'en-têtes de pagination');

        $page = $this->get("/api/collections?q=$tag&status=all&page=2&limit=2");
        $this->assertCount(1, $page, 'page 2 sur 2 éléments par page');
        $this->assertSame(['3', '2', '2'], [$this->client->getResponse()->headers->get('X-Total-Count'), $this->client->getResponse()->headers->get('X-Page'), $this->client->getResponse()->headers->get('X-Limit')]);

        $this->request('GET', "/api/collections?status=bizarre");
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['status' => 'INVALID_VALUE'], json_decode((string) $this->client->getResponse()->getContent(), true)['fields']);

        $type = $this->create(TypeFournisseur::class, ['name' => 'Type ' . $tag]);
        $a = $this->create(Fournisseur::class, ['name' => "Alpha $tag", 'ville' => 'Montréal'], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, $type->getId())));
        $b = $this->create(Fournisseur::class, ['name' => "Bravo $tag", 'ville' => 'Québec'], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, $type->getId())));
        $this->endOfWrite();
        $this->assertSame([$a->getId(), $b->getId()], $ids($this->get("/api/fournisseurs?q=$tag")), 'recherche par nom, tri par nom');
        $byCity = $ids($this->get('/api/fournisseurs?q=qu%C3%A9bec'));
        $this->assertContains($b->getId(), $byCity, 'recherche par ville');
        $this->assertNotContains($a->getId(), $byCity);
        $this->assertSame([$b->getId()], $ids($this->get("/api/fournisseurs?q=$tag&page=2&limit=1")));
        $this->assertSame('2', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testPurchasesCsvExport(): void
    {
        $tag = bin2hex(random_bytes(2));
        $collection = $this->create(Collections::class, ['nomCollection' => "Export $tag", 'del' => false]);
        $type = $this->create(TypeFournisseur::class, ['name' => 'Type export ' . $tag]);
        $fournisseur = $this->create(Fournisseur::class, ['name' => "Fournisseur $tag"], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, $type->getId())));
        $commande = $this->create(Commande::class, ['name' => "Commande $tag", 'budget' => 100000], fn (Commande $c, EntityManagerInterface $em) => $c->setDate(new \DateTime('2026-04-15 10:00:00'))->setCollections($em->find(Collections::class, $collection->getId()))->setFournisseur($em->find(Fournisseur::class, $fournisseur->getId())));
        $carrier = $this->create(Transporteur::class, ['name' => 'Transporteur ' . $tag]);
        $this->create(FraisDePort::class, ['name' => 'Livraison ' . $tag, 'price' => 2500], fn (FraisDePort $f, EntityManagerInterface $em) => $f->setCommande($em->find(Commande::class, $commande->getId()))->setTransporteur($em->find(Transporteur::class, $carrier->getId())));
        $noteType = $this->create(TypeNoteDeFrais::class, ['name' => 'Repas ' . $tag]);
        $this->create(NoteDeFrais::class, ['name' => 'Note ' . $tag, 'montant' => 1250], fn (NoteDeFrais $n, EntityManagerInterface $em) => $n->setDescription('Repas ' . $tag)->setDate(new \DateTime('2026-04-20'))->setCollection($em->find(Collections::class, $collection->getId()))->setTypeNoteDeFrais($em->find(TypeNoteDeFrais::class, $noteType->getId())));
        $em = $this->em();
        $em->getConnection()->executeStatement("INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, commande_id, commande_cost_cents, coefficient_multiplier) VALUES (nextval('product_id_seq'), :n, 4999, false, 3, NOW(), :s, 'standard', :c, 2000, 2.5)", ['n' => 'Produit ' . $tag, 's' => 'export-' . $tag, 'c' => $commande->getId()]);
        $this->endOfWrite();

        $this->request('GET', '/api/gestion/exports/achats.csv?from=2026-04-01&to=2026-04-30&collectionId=' . $collection->getId());
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="achats_2026-04-01_2026-04-30.csv"', $response->headers->get('Content-Disposition'));
        $csv = (string) $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM UTF-8');
        $lines = array_values(array_filter(explode("\r\n", substr($csv, 3))));
        $this->assertSame('Type;Collection;Commande;Date;Fournisseur;Libellé;Quantité;"Coût unitaire (CAD)";"Total (CAD)";Coefficient;"Transporteur / Type de note";"Devise d\'achat";Taux', $lines[0]);
        $this->assertSame("Produit;\"Export $tag\";\"Commande $tag\";2026-04-15;\"Fournisseur $tag\";\"Produit $tag\";3;20,00;60,00;2,5;;;", $lines[1], 'coût d\'achat × quantité, virgule décimale ; devise d\'achat et taux vides dans la devise du site (lot G6)');
        $this->assertSame("\"Frais de port\";\"Export $tag\";\"Commande $tag\";2026-04-15;\"Fournisseur $tag\";\"Livraison $tag\";1;25,00;25,00;;\"Transporteur $tag\";;", $lines[2]);
        $this->assertSame("\"Note de frais\";\"Export $tag\";;2026-04-20;;\"Repas $tag\";1;12,50;12,50;;\"Repas $tag\";;", $lines[3]);
        $this->assertSame('"Total achats";;;;;;;;97,50;;;;', end($lines));

        $this->request('GET', '/api/gestion/exports/achats.csv?from=2026-05-01&to=2026-04-01');
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['to' => 'BEFORE_FROM'], json_decode((string) $this->client->getResponse()->getContent(), true)['fields']);
        $this->request('GET', '/api/gestion/exports/achats.csv?from=hier');
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/gestion/exports/achats.csv', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode(), 'réservé aux administrateurs');
    }

    public function testApiPlatformOnlyDeletesCommandes(): void
    {
        $this->request('GET', '/api/commandes');
        $this->assertContains($this->client->getResponse()->getStatusCode(), [404, 405], 'GET /api/commandes retiré (lot G5)');
        $this->request('GET', '/api/commandes/1');
        $this->assertContains($this->client->getResponse()->getStatusCode(), [404, 405]);
        $this->request('DELETE', '/api/commandes/999999999');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(), 'DELETE conservé (404 pour une commande inconnue)');
    }

    // ----- helpers -----

    private function headers(): array
    {
        return ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token];
    }

    private function request(string $method, string $route): void
    {
        $this->client->request($method, $route, [], [], $this->headers());
    }

    private function get(string $route): array
    {
        $this->request('GET', $route);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route . ' : ' . substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    protected function em(): EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function endOfWrite(): void
    {
        static::getContainer()->get(CacheInvalidationSubscriber::class)->invalidateWrittenTenants();
    }

    private function login(): void
    {
        $email = 'gestion-g5-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Gestion')->setLastname('G5')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}
