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
 * Lot LEX volet 3 (12/11/2026) : en-têtes, colonne Type et lignes de total de `GET /api/gestion/exports/achats.csv` selon
 * `?locale=fr|en`, sans modifier les valeurs des lignes ni le format français (virgule décimale, « ; », BOM).
 */
class PurchasesCsvLocaleTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    public function testPurchasesCsvRespectsLocaleWithoutChangingDataValues(): void
    {
        $tag = bin2hex(random_bytes(2));
        $collection = $this->create(Collections::class, ['nomCollection' => "Export LEX $tag", 'del' => false]);
        $type = $this->create(TypeFournisseur::class, ['name' => 'Type export LEX ' . $tag]);
        $fournisseur = $this->create(Fournisseur::class, ['name' => "Fournisseur LEX $tag"], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, $type->getId())));
        $commande = $this->create(Commande::class, ['name' => "Commande LEX $tag", 'budget' => 100000], fn (Commande $c, EntityManagerInterface $em) => $c->setDate(new \DateTime('2026-04-15 10:00:00'))->setCollections($em->find(Collections::class, $collection->getId()))->setFournisseur($em->find(Fournisseur::class, $fournisseur->getId())));
        $carrier = $this->create(Transporteur::class, ['name' => 'Transporteur LEX ' . $tag]);
        $this->create(FraisDePort::class, ['name' => 'Livraison LEX ' . $tag, 'price' => 2500], fn (FraisDePort $f, EntityManagerInterface $em) => $f->setCommande($em->find(Commande::class, $commande->getId()))->setTransporteur($em->find(Transporteur::class, $carrier->getId())));
        $noteType = $this->create(TypeNoteDeFrais::class, ['name' => 'Repas LEX ' . $tag]);
        $this->create(NoteDeFrais::class, ['name' => 'Note LEX ' . $tag, 'montant' => 1250], fn (NoteDeFrais $n, EntityManagerInterface $em) => $n->setDescription('Repas LEX ' . $tag)->setDate(new \DateTime('2026-04-20'))->setCollection($em->find(Collections::class, $collection->getId()))->setTypeNoteDeFrais($em->find(TypeNoteDeFrais::class, $noteType->getId())));
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO product (id, name, price, isbestseller, quantity, created_at, slug, product_type, commande_id, commande_cost_cents, coefficient_multiplier) VALUES (nextval('product_id_seq'), :n, 4999, false, 3, NOW(), :s, 'standard', :c, 2000, 2.5)",
            ['n' => 'Produit LEX ' . $tag, 's' => 'export-lex-' . $tag, 'c' => $commande->getId()]
        );
        static::getContainer()->get(\App\Services\CommandeService\CommandeLineService::class)->backfill();
        $this->endOfWrite();

        $baseUrl = '/api/gestion/exports/achats.csv?from=2026-04-01&to=2026-04-30&collectionId=' . $collection->getId();
        $defaultCsv = $this->fetchCsv($baseUrl);
        $frCsv = $this->fetchCsv($baseUrl . '&locale=fr');
        $this->assertSame($defaultCsv, $frCsv, 'sans paramètre et ?locale=fr : fichier identique au caractère près');

        $enCsv = $this->fetchCsv($baseUrl . '&locale=en');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $enCsv);
        $frLines = array_values(array_filter(explode("\r\n", substr($frCsv, 3))));
        $enLines = array_values(array_filter(explode("\r\n", substr($enCsv, 3))));
        $this->assertCount(8, $enLines);
        $this->assertSame(
            'Type;Collection;Order;Date;Supplier;Label;Quantity;"Unit cost (CAD)";"Total (CAD)";Coefficient;"Carrier / Expense type";"Purchase currency";Rate',
            $enLines[0]
        );
        // Lignes de données : seule la 1re colonne (Type) change ; collection, commande, fournisseur, libellé et montants à virgule restent identiques
        $this->assertSame(
            'Product;' . substr($frLines[1], strlen('Produit;')),
            $enLines[1]
        );
        $this->assertSame(
            '"Shipping cost";' . substr($frLines[2], strlen('"Frais de port";')),
            $enLines[2]
        );
        $this->assertSame(
            'Expense;' . substr($frLines[3], strlen('"Note de frais";')),
            $enLines[3]
        );
        $this->assertSame('"Products total";;;;;;;;60,00;;;;', $enLines[4]);
        $this->assertSame('"Shipping total";;;;;;;;25,00;;;;', $enLines[5]);
        $this->assertSame('"Expenses total";;;;;;;;12,50;;;;', $enLines[6]);
        $this->assertSame('"Purchases total";;;;;;;;97,50;;;;', $enLines[7]);
    }

    private function fetchCsv(string $route): string
    {
        $this->client->request('GET', $route, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $route);

        return (string) $this->client->getResponse()->getContent();
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
        $email = 'lex-csv-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Lex')->setLastname('Csv')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}

