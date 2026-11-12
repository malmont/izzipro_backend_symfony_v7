<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\EasyPostConfiguration;
use App\Entity\GooglePlacesConfig;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeGooglePlacesHttpClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Adresses d'un client (31/10/2026, application e-commerce mobile) : création et modification sans EasyPost sur le
 * site (adresse gardée telle quelle, province en code), adresse rendue avec le message, isPrimary relu à jour après
 * set-primary, détails d'autocomplétion jamais en 500.
 */
class AdressApiTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';

    private KernelBrowser $client;
    /** @var array<string, string> cookies de session + e-mail */
    private array $session = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function payload(array $extra = []): string
    {
        return json_encode($extra + [
            'firstname' => 'Marie', 'lastname' => 'Tremblay', 'addressLineOne' => '1234 rue Sainte-Catherine O',
            'addressLineTwo' => 'app. 5', 'city' => 'Montréal', 'province' => 'Québec', 'zipCode' => 'H3G 1P1',
            'country' => 'CA', 'contactNumber' => '+1 514 555 0100', 'isPrimary' => false,
        ]);
    }

    public function testAnAddressIsCreatedWithoutEasyPostAndReturnedWithTheMessage(): void
    {
        $this->loginAs();
        $rows = $this->withoutEasyPost();
        try {
            $this->createAndEditWithoutEasyPost();
        } finally {
            $this->restoreEasyPost($rows);
        }
    }

    private function createAndEditWithoutEasyPost(): void
    {
        $response = $this->request('POST', '/api/adresses', $this->payload());
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Adresse créée avec succès', $body['success'], 'message inchangé');
        $address = $body['address'];
        $this->assertSame('1234 rue Sainte-Catherine O', $address['addressLineOne'], 'adresse gardée telle quelle');
        $this->assertSame('QC', $address['province'], 'province ramenée à son code');
        $this->assertSame(['Montréal', 'H3G 1P1', 'CA', false], [$address['city'], $address['zipCode'], $address['country'], $address['isPrimary']]);
        $this->assertIsInt($address['id']);

        $list = $this->json($this->request('GET', '/api/adresses'));
        $this->assertSame([$address], $list, 'la liste rend la même adresse (même DTO)');
    }

    public function testEditingReturnsTheUpdatedAddress(): void
    {
        $this->loginAs();
        $rows = $this->withoutEasyPost();
        try {
            $id = json_decode($this->request('POST', '/api/adresses', $this->payload())->getContent(), true)['address']['id'];

            $body = $this->json($this->request('PUT', '/api/adresses/' . $id, $this->payload(['city' => 'Laval', 'province' => 'ontario', 'isPrimary' => true])));
            $this->assertSame('Adresse mise à jour avec succès', $body['success']);
            $this->assertSame([$id, 'Laval', 'ON', true], [$body['address']['id'], $body['address']['city'], $body['address']['province'], $body['address']['isPrimary']]);
        } finally {
            $this->restoreEasyPost($rows);
        }
    }

    public function testIsPrimaryIsUpToDateRightAfterSetPrimary(): void
    {
        $this->loginAs();
        $rows = $this->withoutEasyPost();
        try {
            $this->setPrimaryScenario();
        } finally {
            $this->restoreEasyPost($rows);
        }
    }

    private function setPrimaryScenario(): void
    {
        $first = json_decode($this->request('POST', '/api/adresses', $this->payload(['isPrimary' => true]))->getContent(), true)['address']['id'];
        $second = json_decode($this->request('POST', '/api/adresses', $this->payload(['addressLineOne' => '2 rue Deux']))->getContent(), true)['address']['id'];
        $this->assertSame([$first => true, $second => false], $this->primaryById(), 'avant : la première est principale');

        $body = $this->json($this->request('PUT', '/api/adresses/' . $second . '/set-primary'));
        $this->assertSame('Adresse principale mise à jour avec succès', $body['success']);
        $this->assertSame([$second, true], [$body['address']['id'], $body['address']['isPrimary']], 'l\'adresse est rendue avec le message');
        $this->assertSame([$first => false, $second => true], $this->primaryById(), 'relue aussitôt : la seconde est principale');

        $body = $this->json($this->request('PUT', '/api/adresses/' . $first, $this->payload(['isPrimary' => true])));
        $this->assertTrue($body['address']['isPrimary']);
        $this->assertSame([$first => true, $second => false], $this->primaryById(), 'un PUT qui ne change que isPrimary est relu aussitôt');

        $this->assertSame(204, $this->request('DELETE', '/api/adresses/' . $second)->getStatusCode(), 'suppression : 204 (répondait 500, entité détachée)');
        $this->assertSame([$first => true], $this->primaryById());
    }

    public function testAddressDetailsNeverAnswer500(): void
    {
        $em = $this->em();
        $this->assertNull($em->getRepository(GooglePlacesConfig::class)->findOneBy([]), 'site de test sans clé Google Places');

        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/adresses/details?place_id=inconnu', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(), 'sans clé : 404 avec un code explicite, jamais 500 (nginx masque les corps 5xx)');
        $this->assertSame('AUTOCOMPLETE_NOT_CONFIGURED', json_decode($this->client->getResponse()->getContent(), true)['error']);
        $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/adresses/autocomplete?q=1234', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
        $this->assertSame([200, []], [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent(), true)], 'suggestions : [] sans clé (inchangé)');

        $em->persist((new GooglePlacesConfig())->setGoogleApiKeyTest('cle-de-test'));
        $em->flush();
        try {
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/adresses/details', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(400, $this->client->getResponse()->getStatusCode(), 'place_id manquant');
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/adresses/details?place_id=inconnu', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(404, $this->client->getResponse()->getStatusCode(), 'identifiant inconnu : 404');
            $this->assertSame('PLACE_NOT_FOUND', json_decode($this->client->getResponse()->getContent(), true)['error']);
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/adresses/details?place_id=' . FakeGooglePlacesHttpClient::KNOWN_PLACE_ID, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $details = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame(['1 rue du Test', 'Montréal', 'QC', 'H1A 1A1', 'CA'], [$details['street1'], $details['city'], $details['province'], $details['postalCode'], $details['country']]);
            $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/adresses/autocomplete?q=1234', [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(FakeGooglePlacesHttpClient::KNOWN_PLACE_ID, json_decode($this->client->getResponse()->getContent(), true)[0]['placeId']);
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM google_places_config');
        }
    }

    public function testThePostalCodeFollowsTheCountry(): void
    {
        $this->loginAs();
        $rows = $this->withoutEasyPost();
        try {
            $cases = [
                // [pays, code postal, statut attendu, message attendu (400)]
                ['CA', 'H3G 1P1', 201, null],
                ['Canada', 'h3g1p1', 201, null],
                ['CA', '75008', 400, 'format canadien A1A 1A1'],
                ['US', '10001', 201, null],
                ['US', '10001-1234', 201, null],
                ['États-Unis', 'H3G 1P1', 400, 'format américain'],
                ['FR', '75008', 201, null],
                ['GB', 'SW1A 1AA', 201, null],
                ['FR', '7', 400, '3 à 10'],
                ['FR', 'ABCDEFGHIJK', 400, '3 à 10'],
            ];
            foreach ($cases as [$country, $zip, $status, $message]) {
                $response = $this->request('POST', '/api/adresses', $this->payload(['country' => $country, 'zipCode' => $zip, 'province' => 'Région']));
                $this->assertSame($status, $response->getStatusCode(), "$country $zip : " . $response->getContent());
                $body = json_decode($response->getContent(), true);
                if ($message !== null) {
                    $this->assertStringContainsString($message, $body['error']['zipCode'], "$country $zip");
                } else {
                    $this->assertSame($zip, $body['address']['zipCode'], 'code postal gardé tel quel');
                }
            }
        } finally {
            $this->restoreEasyPost($rows);
        }
    }

    /**
     * Codes stables par champ (02/11/2026) : `error {champ: message}` inchangé, plus `code` et errors[{path, code, message, params?}] :
     * FIELD_REQUIRED, PHONE_INVALID, POSTAL_CODE_INVALID {country} ; ADDRESS_UNDELIVERABLE quand EasyPost refuse l'adresse.
     */
    public function testRefusedFieldsCarryTheirCodes(): void
    {
        $this->loginAs();
        $rows = $this->withoutEasyPost();
        try {
            $response = $this->request('POST', '/api/adresses', $this->payload(['firstname' => '', 'contactNumber' => 'abc', 'zipCode' => '75008', 'country' => 'CA']));
            $this->assertSame(400, $response->getStatusCode(), $response->getContent());
            $body = json_decode($response->getContent(), true);
            $this->assertEqualsCanonicalizing(['firstname', 'zipCode', 'contactNumber'], array_keys($body['error']), 'error {champ: message} inchangé');
            $this->assertSame('Le prénom est obligatoire.', $body['error']['firstname']);
            $this->assertSame('VALIDATION_FAILED', $body['code']);
            $byCode = array_column($body['errors'], null, 'code');
            $this->assertSame(['path' => 'firstname', 'code' => 'FIELD_REQUIRED', 'message' => 'Le prénom est obligatoire.'], $byCode['FIELD_REQUIRED']);
            $this->assertSame(['path' => 'contactNumber', 'code' => 'PHONE_INVALID', 'message' => $body['error']['contactNumber']], $byCode['PHONE_INVALID']);
            $this->assertSame(['path' => 'zipCode', 'code' => 'POSTAL_CODE_INVALID', 'message' => 'Le code postal doit être au format canadien A1A 1A1.', 'params' => ['country' => 'CA']], $byCode['POSTAL_CODE_INVALID']);

            // Modification : même forme
            $created = json_decode($this->request('POST', '/api/adresses', $this->payload())->getContent(), true);
            $edited = json_decode($this->request('PUT', '/api/adresses/' . $created['address']['id'], $this->payload(['zipCode' => '1', 'country' => 'FR']))->getContent(), true);
            $this->assertSame('VALIDATION_FAILED', $edited['code']);
            $this->assertSame([['path' => 'zipCode', 'code' => 'POSTAL_CODE_INVALID', 'message' => 'Le code postal doit compter de 3 à 10 lettres ou chiffres.', 'params' => ['country' => 'FR']]], $edited['errors']);
            $this->assertSame(['zipCode' => 'Le code postal doit compter de 3 à 10 lettres ou chiffres.'], $edited['error']);
        } finally {
            $this->restoreEasyPost($rows);
        }

        // Refus d'EasyPost (adresse non livrable) : vérification simulée, sans appel réseau (noyau neuf : le service n'est pas encore instancié)
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(\App\Services\AdressService\AddressVerificationService::class, new class extends \App\Services\AdressService\AddressVerificationService {
            public function __construct()
            {
            }

            public function verifyOrKeep(array $address): array
            {
                throw new \App\Services\AdressService\AddressUndeliverableException('Adresse invalide : Address not found'); // le refus du service, nommé (lot ERREURS-1)
            }
        });
        $response = $this->request('POST', '/api/adresses', $this->payload());
        $this->assertSame(400, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(['address' => 'Adresse invalide : Address not found'], $body['error'], 'error.address inchangé');
        $this->assertSame('ADDRESS_UNDELIVERABLE', $body['code']);
        $this->assertSame([['path' => 'address', 'code' => 'ADDRESS_UNDELIVERABLE', 'message' => 'Adresse invalide : Address not found']], $body['errors']);
    }

    /**
     * Retire la configuration EasyPost du site de test (le modèle en porte une) le temps du test, et la remet.
     *
     * @return list<array<string, mixed>> lignes retirées, à rendre à restoreEasyPost()
     */
    private function withoutEasyPost(): array
    {
        $connection = $this->em()->getConnection();
        $rows = $connection->fetchAllAssociative('SELECT * FROM easy_post_configuration');
        $connection->executeStatement('DELETE FROM easy_post_configuration');
        $this->assertNull($this->em()->getRepository(EasyPostConfiguration::class)->findOneBy([]), 'site sans EasyPost');

        return $rows;
    }

    private function restoreEasyPost(array $rows): void
    {
        $connection = $this->em()->getConnection();
        foreach ($rows as $row) {
            $connection->insert('easy_post_configuration', $row);
        }
    }

    /** @return array<int, bool> isPrimary par identifiant, relu par GET /api/adresses */
    private function primaryById(): array
    {
        $out = [];
        foreach ($this->json($this->request('GET', '/api/adresses')) as $address) {
            $out[$address['id']] = $address['isPrimary'];
        }
        ksort($out);

        return $out;
    }

    private function json(Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true);
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($this->session as $name => $value) {
            if ($name !== 'email') {
                $jar->set(new \Symfony\Component\BrowserKit\Cookie($name, $value, null, '/', MV_TEST_TENANT_HOST, true));
            }
        }
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->session['XSRF-TOKEN_' . MV_TEST_TENANT_CODE] ?? null,
        ]), $body);
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }

        return $this->client->getResponse();
    }

    private function loginAs(): void
    {
        $email = 'adresses-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $em = $this->em();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Marie')->setLastname('Tremblay')
            ->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/api/login', [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'web']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->session = ['email' => $email];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $this->session[$cookie->getName()] = $cookie->getValue();
        }
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
