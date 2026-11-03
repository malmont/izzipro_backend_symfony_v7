<?php

namespace App\Tests\Functional\Email;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\AdressService\AddressAutocompleteService;
use App\Services\AdressService\GooglePlacesKeyProvider;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Fake\FakeCheckoutPaymentGateway;
use App\Tests\Fake\FakeSubscriptionStripeGateway;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Courriels destinés au client en fr et en (03/11/2026, lot L6b de l'application e-commerce mobile) : la langue vient de `?locale=`
 * (fr, en), sinon de la langue du site (adresse de la fiche Entreprise : Québec → fr, autre province ou États-Unis → en) ; le texte
 * français est celui d'avant. Devise du site dans les courriels de commande ; `language` transmis à Google Places. Le site de test
 * est au Québec (fr) : c'est la langue de repli vérifiée ici.
 */
class CustomerEmailLanguageTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private \Doctrine\DBAL\Connection $db;
    private ?string $ip = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $this->db = $provider->getEntityManager()->getConnection();
        // Site de référence des tests : Québec, en dollars canadiens (des tests antérieurs laissent la France, l'Ontario ou l'euro)
        $this->db->executeStatement("UPDATE entreprise SET email = 'direction@mvtest.test', currency = 'CAD'");
        $this->db->executeStatement("UPDATE address_entreprise SET country = 'Canada', state = 'QC', city = 'Montréal', zip = 'H1M 0A9'");
        FakeCheckoutPaymentGateway::reset();
        FakeSubscriptionStripeGateway::reset();
    }

    public function testTheVerificationCodeFollowsTheRequestedLanguage(): void
    {
        [$email] = $this->user(otp: true);

        $this->login($email, '?locale=en', 200);
        $message = $this->lastMessageTo($email);
        $this->assertSame('Your verification code', $message->getSubject());
        $this->assertStringContainsString('Your verification code is:', (string) $message->getHtmlBody());
        $this->assertStringContainsString('This code will expire in 5 minutes.', (string) $message->getHtmlBody());

        $this->login($email, '?locale=fr', 200);
        $message = $this->lastMessageTo($email);
        $this->assertSame('Votre code OTP', $message->getSubject(), 'français : sujet d\'avant');
        $this->assertStringContainsString('Votre code de vérification est : <strong>', (string) $message->getHtmlBody());
        $this->assertStringContainsString('Ce code expirera dans 5 minutes.', (string) $message->getHtmlBody());

        $this->login($email, '', 200);
        $this->assertSame('Votre code OTP', $this->lastMessageTo($email)->getSubject(), 'sans locale : langue du site (Québec → fr)');
    }

    public function testTheRegistrationEmailsFollowTheRequestedLanguage(): void
    {
        $email = 'inscription-' . bin2hex(random_bytes(3)) . '@example.com';
        $this->assertSame(201, $this->request('POST', '/api/register?locale=en', json_encode(['email' => $email, 'password' => self::PASSWORD, 'firstName' => 'Jane', 'lastName' => 'Doe', 'app' => 'mobile']))->getStatusCode());
        $message = $this->lastMessageTo($email);
        $this->assertSame('Please confirm your email address', $message->getSubject());
        $this->assertStringContainsString('Hello Jane!', (string) $message->getHtmlBody());
        $this->assertStringContainsString('Thank you for signing up.', (string) $message->getHtmlBody());
        $this->assertStringContainsString('>Validate my account</a>', (string) $message->getHtmlBody());

        $this->assertSame(200, $this->request('POST', '/api/resend-verification?locale=en', json_encode(['email' => $email]))->getStatusCode());
        $this->assertSame('New account validation link', $this->lastMessageTo($email)->getSubject());

        $french = 'inscription-' . bin2hex(random_bytes(3)) . '@example.com';
        $this->assertSame(201, $this->request('POST', '/api/register?locale=fr', json_encode(['email' => $french, 'password' => self::PASSWORD, 'firstName' => 'Marie', 'lastName' => 'Tremblay']))->getStatusCode());
        $message = $this->lastMessageTo($french);
        $this->assertSame('Veuillez valider votre adresse email', $message->getSubject());
        $this->assertStringContainsString('Bonjour, Marie !', (string) $message->getHtmlBody());
        $this->assertStringContainsString('Merci de vous être inscrit. Pour activer votre compte, veuillez cliquer sur le lien ci-dessous :', (string) $message->getHtmlBody());
        $this->assertStringContainsString('>Valider mon compte</a>', (string) $message->getHtmlBody());
        $this->assertSame(200, $this->request('POST', '/api/resend-verification', json_encode(['email' => $french]))->getStatusCode());
        $this->assertSame('Nouveau lien de validation de compte', $this->lastMessageTo($french)->getSubject(), 'sans locale : fr');
    }

    public function testThePasswordResetEmailFollowsTheRequestedLanguage(): void
    {
        [$email] = $this->user();
        $this->assertSame(200, $this->request('POST', '/api/password-reset/request?locale=en', json_encode(['email' => $email]))->getStatusCode());
        $message = $this->lastMessageTo($email);
        $this->assertSame('Reset your password', $message->getSubject());
        $this->assertStringContainsString('You requested a password reset.', (string) $message->getHtmlBody());
        $this->assertStringContainsString('>Reset my password</a>', (string) $message->getHtmlBody());
        $this->assertStringContainsString('locale=en', (string) $message->getHtmlBody(), 'le lien vers la page du site garde la locale');

        [$french] = $this->user();
        $this->assertSame(200, $this->request('POST', '/api/password-reset/request?locale=fr', json_encode(['email' => $french]))->getStatusCode());
        $message = $this->lastMessageTo($french);
        $this->assertSame('Réinitialisation du mot de passe', $message->getSubject(), 'sujet français (« Password Reset » hérité remplacé le 03/11/2026, accord du propriétaire)');
        $this->assertStringContainsString('Vous avez demandé la réinitialisation de votre mot de passe.', (string) $message->getHtmlBody());
        $this->assertStringContainsString('>Réinitialiser mon mot de passe</a>', (string) $message->getHtmlBody());
        $this->assertStringContainsString('Ce lien expirera dans 1 heure.', (string) $message->getHtmlBody());
    }

    public function testAnUnknownOrMissingLocaleTakesTheLanguageOfTheSite(): void
    {
        [$email] = $this->user();
        try {
            // Site d'une autre province du Canada : anglais
            $this->db->executeStatement("UPDATE address_entreprise SET country = 'CA', state = 'ON'");
            $this->assertSame(200, $this->request('POST', '/api/password-reset/request?locale=de', json_encode(['email' => $email]))->getStatusCode());
            $this->assertSame('Reset your password', $this->lastMessageTo($email)->getSubject(), 'locale inconnue : langue du site (Ontario → en)');

            // Site en France : français, et montants en euros vérifiés dans testTheOrderEmailsUseTheCurrencyOfTheSite
            $this->db->executeStatement("UPDATE address_entreprise SET state = '', country = 'FR'");
            $this->assertSame(200, $this->request('POST', '/api/password-reset/request', json_encode(['email' => $email]))->getStatusCode());
            $this->assertSame('Réinitialisation du mot de passe', $this->lastMessageTo($email)->getSubject(), 'sans locale : France → fr');
        } finally {
            $this->db->executeStatement("UPDATE address_entreprise SET state = 'QC', country = 'Canada'");
        }
    }

    public function testTheEmailChangedNoticeFollowsTheRequestedLanguage(): void
    {
        [$email] = $this->user();
        $token = $this->login($email);
        $new = 'nouvelle-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $response = $this->request('PATCH', '/api/profile?locale=en', json_encode(['email' => $new, 'currentPassword' => self::PASSWORD]), $token);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $message = $this->lastMessageTo($email);
        $this->assertSame('Your email address has been changed', $message->getSubject(), 'avis à l\'ancienne adresse');
        $this->assertStringContainsString('The email address of your account was replaced on', (string) $message->getHtmlBody());
        $this->assertStringContainsString('If you made this change, there is nothing you need to do.', (string) $message->getHtmlBody());

        [$french] = $this->user();
        $token = $this->login($french);
        $response = $this->request('PATCH', '/api/profile?locale=fr', json_encode(['email' => 'autre-' . bin2hex(random_bytes(3)) . '@example.invalid', 'currentPassword' => self::PASSWORD]), $token);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $message = $this->lastMessageTo($french);
        $this->assertSame('Votre adresse courriel a été modifiée', $message->getSubject());
        $html = (string) $message->getHtmlBody();
        $this->assertStringContainsString('Bonjour Marie,', $html);
        $this->assertMatchesRegularExpression('#L\'adresse courriel de votre compte a été remplacée le \d{2}/\d{2}/\d{4} à \d{2}:\d{2} : l\'ancienne adresse <strong>#', $html, 'texte et date d\'avant');
        $this->assertStringContainsString('Si vous êtes à l\'origine de ce changement, vous n\'avez rien à faire.', $html);
    }

    public function testTheAccountDeletedNoticeFollowsTheRequestedLanguage(): void
    {
        [$email] = $this->user();
        $response = $this->request('DELETE', '/api/account?locale=en', json_encode(['password' => self::PASSWORD]), $this->login($email));
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $message = $this->lastMessageTo($email);
        $this->assertSame('Your account has been deleted', $message->getSubject());
        $this->assertStringContainsString('At your request, your account linked to the address <strong>' . $email . '</strong> was deleted on', (string) $message->getHtmlBody());

        [$french] = $this->user();
        $response = $this->request('DELETE', '/api/account', json_encode(['password' => self::PASSWORD]), $this->login($french));
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $message = $this->lastMessageTo($french);
        $this->assertSame('Votre compte a été supprimé', $message->getSubject(), 'sans locale : fr');
        $this->assertMatchesRegularExpression('#À votre demande, votre compte associé à l\'adresse <strong>' . preg_quote($french, '#') . '</strong> a été supprimé le \d{2}/\d{2}/\d{4} à \d{2}:\d{2}\.#', (string) $message->getHtmlBody());
        $this->assertStringContainsString('Vos données personnelles (nom, adresses, téléphone, adresse courriel) ont été effacées.', (string) $message->getHtmlBody());
    }

    public function testTheOrderConfirmationFollowsTheCheckoutLanguageAndTheMerchantNoticeStaysFrench(): void
    {
        $this->seedShop();
        $guest = 'invite-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $this->guestOrder($guest, '?locale=en');
        $confirmation = $this->lastMessageTo($guest);
        $this->assertStringStartsWith('Confirmation of your order no. ', $confirmation->getSubject());
        $html = (string) $confirmation->getHtmlBody();
        $this->assertStringContainsString('Thank you for your order, Invité!', $html);
        $this->assertStringContainsString('Order details', $html);
        $this->assertStringContainsString('Shipping address', $html);
        $this->assertMatchesRegularExpression('#Total:</td>\s*<td[^>]*>\s*\$\d#', $html, 'montant au format en_CA ($12.00), devise du site CAD');
        $this->assertStringNotContainsString('CA$', $html);
        $merchant = $this->lastMessageTo('direction@mvtest.test');
        $this->assertStringStartsWith('Nouvelle commande à expédier : ', $merchant->getSubject(), 'courriel du marchand : français');
        $this->assertStringContainsString('Nouvelle commande à préparer', (string) $merchant->getHtmlBody());
        $this->assertStringNotContainsString('CA$', (string) $merchant->getHtmlBody(), 'montant au format du site (fr_CA)');

        $french = 'invite-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $this->guestOrder($french, '?locale=fr');
        $confirmation = $this->lastMessageTo($french);
        $this->assertStringStartsWith('Confirmation de votre commande n°', $confirmation->getSubject());
        $html = (string) $confirmation->getHtmlBody();
        $this->assertStringContainsString('Merci pour votre commande, Invité !', $html);
        $this->assertStringContainsString('Détails de la commande', $html);
        $this->assertStringContainsString('Adresse de livraison', $html);
        $this->assertMatchesRegularExpression('#Total :</td>\s*<td[^>]*>\s*\d+,\d{2}\s*\$#u', $html, 'montant au format fr_CA (12,00 $)');
    }

    public function testTheOrderEmailsUseTheCurrencyOfTheSite(): void
    {
        $this->seedShop();
        // Site en France, en euros : commande sans devise propre (la commande n'en enregistre pas) → courriels en euros, au format fr_FR
        $this->db->executeStatement("UPDATE entreprise SET currency = 'EUR'");
        $this->db->executeStatement("UPDATE address_entreprise SET state = '', country = 'FR'");
        try {
            $guest = 'invite-' . bin2hex(random_bytes(3)) . '@example.invalid';
            $this->guestOrder($guest, '');
            $html = (string) $this->lastMessageTo($guest)->getHtmlBody();
            $this->assertStringContainsString('Merci pour votre commande', $html, 'sans locale : langue du site (France → fr)');
            $this->assertMatchesRegularExpression('#Total :</td>\s*<td[^>]*>\s*\d+,\d{2}\s*€#u', $html, 'euros au format français');
            $this->assertStringNotContainsString('$', preg_replace('#<[^>]+>#', '', $html));
            $this->assertStringContainsString('€', (string) $this->lastMessageTo('direction@mvtest.test')->getHtmlBody(), 'avis du marchand en euros aussi');
        } finally {
            $this->db->executeStatement("UPDATE entreprise SET currency = 'CAD'");
            $this->db->executeStatement("UPDATE address_entreprise SET state = 'QC', country = 'Canada'");
        }
    }

    public function testGooglePlacesReceivesTheLanguageOfTheRequest(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = $url;

            return new MockResponse(json_encode(['status' => 'OK', 'predictions' => [['description' => '1 Main St', 'place_id' => 'abc']], 'result' => ['address_components' => []]]));
        });
        $keys = new class() extends GooglePlacesKeyProvider {
            public function __construct()
            {
            }

            public function getKey(): string
            {
                return 'cle-de-test';
            }
        };
        $service = new AddressAutocompleteService($client, $keys, new NullLogger());

        $this->assertSame([['description' => '1 Main St', 'place_id' => 'abc']], $service->suggest('1 main', 'en'));
        $service->getDetails('abc', 'fr');
        $service->suggest('1 main');
        $this->assertStringContainsString('language=en', $urls[0], 'suggestions dans la langue du client');
        $this->assertStringContainsString('language=fr', $urls[1], 'détails (noms de pays et de ville) dans la langue du client');
        $this->assertStringNotContainsString('language=', $urls[2], 'sans langue : requête d\'avant');
    }

    private function seedShop(): void
    {
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-paiement@example.invalid', false, false);
        $this->db->executeStatement('DELETE FROM boutique_setting');
        $this->db->executeStatement("UPDATE entreprise SET email = 'direction@mvtest.test'");
    }

    /** Commande d'invité payée (Stripe simulé) avec la locale donnée sur l'intention et sur la création */
    private function guestOrder(string $email, string $query): void
    {
        $variant = (int) $this->db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-CASQUETTE' ORDER BY v.id LIMIT 1");
        $body = [
            'guestInfo' => ['email' => $email, 'firstName' => 'Invité', 'lastName' => 'Test', 'phone' => '5145550100'],
            'items' => [['productVariantId' => $variant, 'quantity' => 1]], 'carrierId' => 6,
            'shippingAddress' => ['firstname' => 'Invité', 'lastname' => 'Test', 'address' => '1 rue du Test', 'city' => 'Québec', 'province' => 'QC', 'postalCode' => 'G1R 4P5'],
        ];
        $intent = $this->request('POST', '/api/stripe/create-intent' . $query, json_encode(['items' => $body['items'], 'carrierId' => 6, 'shippingAddress' => ['province' => 'QC']]));
        $this->assertSame(200, $intent->getStatusCode(), $intent->getContent());
        $pi = json_decode($intent->getContent(), true)['paymentIntentId'];
        FakeCheckoutPaymentGateway::confirm($pi);
        $created = $this->request('POST', '/api/order/create-guest' . $query, json_encode($body + ['paymentIntentId' => $pi]));
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
    }

    private function login(string $email, string $query = '', int $expected = 200): string
    {
        $login = $this->request('POST', '/api/login' . $query, json_encode(['username' => $email, 'password' => self::PASSWORD, 'platform' => 'mobile']));
        $this->assertSame($expected, $login->getStatusCode(), $login->getContent());

        return (string) (json_decode($login->getContent(), true)['token'] ?? '');
    }

    /** Dernier courriel remis au serveur d'envoi pour cette adresse pendant le test */
    private function lastMessageTo(string $email): Email
    {
        $messages = array_values(array_filter(array_map(fn ($event) => $event->getMessage(), array_filter(self::getMailerEvents(), fn ($event) => !$event->isQueued())),
            fn ($message) => $message instanceof Email && ($message->getTo()[0] ?? null)?->getAddress() === $email));
        $this->assertNotEmpty($messages, 'aucun courriel pour ' . $email);

        return $messages[count($messages) - 1];
    }

    private function request(string $method, string $path, ?string $body = null, ?string $bearer = null): Response
    {
        // IP propre au test : les limites de débit (Redis) survivent d'un lancement à l'autre, celles du mot de passe oublié comptent par site et IP
        $this->ip ??= '10.82.' . random_int(1, 254) . '.' . random_int(1, 254);
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], array_filter([
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $bearer ? 'Bearer ' . $bearer : null, 'REMOTE_ADDR' => $this->ip,
        ]), $body);

        return $this->client->getResponse();
    }

    /** @return array{0: string, 1: int} client vérifié, mot de passe choisi, avec une adresse principale */
    private function user(bool $otp = false): array
    {
        $email = 'client-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Marie')->setLastname('Tremblay')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true)->setPasswordSet(true)->setOtpEnabled($otp);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $address = (new Adress())->setFirstname('Marie')->setLastname('Tremblay')->setFullname('Tremblay Marie')->setAddress('1 rue du Test')->setCity('Montréal')
            ->setCodepostal('H1A 1A1')->setCountry('CA')->setPhone('+1 514 555 0000')->setProvince('QC')->setUserAdress($user);
        $em->persist($user);
        $em->persist($address);
        $em->flush();
        $user->setPrimaryAddress($address);
        $em->flush();

        return [$email, (int) $user->getId()];
    }
}
