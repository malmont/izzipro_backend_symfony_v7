<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot « console de pilotage » 1 (04/11/2026) : les activations des modules de la fiche entreprise sont facturées par la plateforme et
 * réservées au super administrateur aussi par l'API (PUT /api/entreprise/{id}), comme dans EasyAdmin depuis le lot 2a du 03/11/2026.
 * Un administrateur de site qui renvoie une activation avec sa valeur actuelle est servi (l'éditeur renvoie parfois la fiche) ;
 * une valeur différente → 403 SUPER_ADMIN_REQUIRED avec la liste des champs, et rien n'est écrit.
 */
class EntrepriseApiSuperAdminGuardTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'admin@arkanoa-media.com';
    private const SUPER_EMAIL = 'super-admin-entreprise@example.invalid';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private int $entrepriseId;
    private bool $boutiqueBefore;
    private bool $esgBefore;
    private ?string $metaTitleBefore;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        $row = $this->db->fetchAssociative('SELECT id, is_boutique_active, is_boussole_esg_active, meta_title FROM entreprise ORDER BY id LIMIT 1');
        $this->assertNotFalse($row, 'la copie de base porte une fiche entreprise');
        $this->entrepriseId = (int) $row['id'];
        $this->boutiqueBefore = (bool) $row['is_boutique_active'];
        $this->esgBefore = (bool) $row['is_boussole_esg_active'];
        $this->metaTitleBefore = $row['meta_title'];
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('UPDATE entreprise SET is_boutique_active = ?, is_boussole_esg_active = ?, meta_title = ? WHERE id = ?', [$this->boutiqueBefore, $this->esgBefore, $this->metaTitleBefore, $this->entrepriseId], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER]);
        parent::tearDown();
    }

    public function testASiteAdminCannotChangeModuleActivationsButUnchangedValuesAreIgnored(): void
    {
        [$status, $body] = $this->put(['isBoutiqueActive' => !$this->boutiqueBefore, 'isBoussoleEsgActive' => $this->esgBefore, 'metaTitle' => 'Titre refusé'], 'admin');
        $this->assertSame(403, $status, json_encode($body));
        $this->assertSame(['SUPER_ADMIN_REQUIRED', 'SUPER_ADMIN_REQUIRED', ['isBoutiqueActive']], [$body['error'], $body['code'], $body['fields']], 'seule l\'activation réellement modifiée est citée');
        $this->assertSame([$this->boutiqueBefore, $this->metaTitleBefore], $this->current(), 'rien n\'est écrit, pas même les autres champs');

        [$status, $body] = $this->put(['isBoutiqueActive' => $this->boutiqueBefore, 'isBoussoleEsgActive' => $this->esgBefore, 'metaTitle' => 'Titre accepté'], 'admin');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([$this->boutiqueBefore, 'Titre accepté'], $this->current(), 'activations renvoyées inchangées : ignorées, le reste est écrit');
    }

    public function testTheSuperAdminChangesModuleActivationsThroughTheApi(): void
    {
        [$status, $body] = $this->put(['isBoutiqueActive' => !$this->boutiqueBefore], 'super');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(!$this->boutiqueBefore, (bool) $body['isBoutiqueActive']);
        $this->assertSame(!$this->boutiqueBefore, $this->current()[0]);
    }

    /** @return array{0: bool, 1: ?string} */
    private function current(): array
    {
        $row = $this->db->fetchAssociative('SELECT is_boutique_active, meta_title FROM entreprise WHERE id = ?', [$this->entrepriseId]);

        return [(bool) $row['is_boutique_active'], $row['meta_title']];
    }

    /** @return array{0: int, 1: array} */
    private function put(array $body, string $as): array
    {
        $this->client->request('PUT', 'https://' . MV_TEST_TENANT_HOST . '/api/entreprise/' . $this->entrepriseId . '?locale=fr', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($as),
        ], json_encode($body));
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
    }

    /** Jeton émis directement (le code OTP du super administrateur ne s'applique qu'à /api/login) */
    private function token(string $as): string
    {
        $container = static::getContainer();
        $em = $container->get(TenantEntityManagerProvider::class)->getEntityManager();
        $email = $as === 'super' ? self::SUPER_EMAIL : self::ADMIN_EMAIL;
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $this->assertSame('super', $as, 'l\'administrateur de test existe dans la copie de base');
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Super')->setLastname('Test')
                ->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])->setPassword('inutilisable')->setIsVerified(true);
            $em->persist($user);
            $em->flush();
        }

        return $container->get('lexik_jwt_authentication.jwt_manager')->create($user);
    }
}
