<?php

namespace App\Tests\Functional\Gestion;

use App\Controller\CollectionsController\CollectionController;
use App\Controller\CommandeController\CommandeController;
use App\Dto\CollectionInputDTO;
use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\TypeFournisseur;
use App\Entity\User;
use App\EventSubscriber\CacheInvalidationSubscriber;
use App\Services\CollectionService\CollectionService;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Cache\CacheTestEntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lot LEX volet 4 (12/11/2026, `docs/erreurs-client.md`) : les quatre refus métier de la gestion qui restaient en anglais
 * passent en français (statut HTTP et clé `error` conservés, `code` et `message` ajoutés sur les trois réponses JSON).
 */
class LexErrorMessagesTest extends WebTestCase
{
    use CacheTestEntityFactory;

    private KernelBrowser $client;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->login();
    }

    public function testInvalidJsonOnFraisDePortCreate(): void
    {
        $tag = bin2hex(random_bytes(2));
        $collection = $this->create(Collections::class, ['nomCollection' => "LEX $tag", 'del' => false, 'isClosed' => false]);
        $type = $this->create(TypeFournisseur::class, ['name' => 'Type LEX ' . $tag]);
        $fournisseur = $this->create(Fournisseur::class, ['name' => 'Fournisseur LEX ' . $tag], fn (Fournisseur $f, EntityManagerInterface $em) => $f->setTypeFournisseur($em->find(TypeFournisseur::class, $type->getId())));
        $commande = $this->create(Commande::class, ['name' => 'Commande LEX ' . $tag, 'budget' => 10000], fn (Commande $c, EntityManagerInterface $em) => $c->setCollections($em->find(Collections::class, $collection->getId()))->setFournisseur($em->find(Fournisseur::class, $fournisseur->getId())));
        $this->endOfWrite();

        foreach (['pas-du-json', '{}'] as $raw) {
            $this->client->request(
                'POST',
                '/api/commandes/' . $commande->getId() . '/frais-de-port',
                [],
                [],
                ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token],
                $raw
            );
            $response = $this->client->getResponse();
            $body = json_decode((string) $response->getContent(), true);
            $this->assertSame(400, $response->getStatusCode(), $raw);
            $this->assertSame('Corps JSON invalide', $body['error'] ?? null);
            $this->assertSame('INVALID_JSON', $body['code'] ?? null);
            $this->assertSame('Corps JSON invalide', $body['message'] ?? null);
        }
    }

    public function testUnauthenticatedControllerResponsesAndUnknownUser(): void
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(null);
        $collection = $this->create(Collections::class, ['nomCollection' => 'LEX Auth ' . bin2hex(random_bytes(2)), 'del' => false, 'isClosed' => false]);
        $this->endOfWrite();

        $commandeRes = $container->get(CommandeController::class)->createCommande(new Request([], [], [], [], [], [], '{}'), $collection);
        $commandeBody = json_decode((string) $commandeRes->getContent(), true);
        $this->assertSame(401, $commandeRes->getStatusCode());
        $this->assertSame(['Authentification requise', 'UNAUTHENTICATED', 'Authentification requise'], [$commandeBody['error'] ?? null, $commandeBody['code'] ?? null, $commandeBody['message'] ?? null]);

        $collectionRes = $container->get(CollectionController::class)->createCollection(new Request([], [], [], [], [], [], '{}'));
        $collectionBody = json_decode((string) $collectionRes->getContent(), true);
        $this->assertSame(401, $collectionRes->getStatusCode());
        $this->assertSame(['Authentification requise', 'UNAUTHENTICATED', 'Authentification requise'], [$collectionBody['error'] ?? null, $collectionBody['code'] ?? null, $collectionBody['message'] ?? null]);

        try {
            $container->get(CollectionService::class)->createCollection(new CollectionInputDTO([
                'budgetCollection' => 1000,
                'nomCollection' => 'LEX User absent',
                'userId' => 999999999,
            ]));
            $this->fail('Exception attendue pour un utilisateur inconnu');
        } catch (\Exception $e) {
            $this->assertSame('Utilisateur introuvable', $e->getMessage());
        }
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
        $email = 'lex-err-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Lex')->setLastname('Erreurs')->setRoles(['ROLE_ADMIN'])->setIsVerified(true)->setPassword('inutilisable');
        $em->persist($user);
        $em->flush();
        $this->token = static::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);
        $this->endOfWrite();
    }
}

