<?php

namespace App\Tests\Functional\Security;

use App\Controller\Account\RegistrationController;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * B16 (04/11/2026) : ne plus révéler si un courriel a un compte. Le renvoi du lien de vérification rend la même réponse 200 pour un
 * compte à activer, un courriel inconnu et un compte déjà activé (courriel envoyé dans le premier cas seulement) et se limite par
 * site et courriel ; otp-verify rend 401 OTP_INVALID pour un courriel inconnu comme pour un code faux.
 */
class AccountEnumerationTest extends WebTestCase
{
    private const PASSWORD = 'Mot-de-passe-de-test-1!';
    private KernelBrowser $client;
    private ?string $ip = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheThreeAnswersOfTheResendAreIdenticalAndOnlyThePendingAccountGetsAnEmail(): void
    {
        $pending = $this->user(verified: false);
        $verified = $this->user(verified: true);
        $unknown = 'personne-' . bin2hex(random_bytes(3)) . '@example.invalid';

        $answers = [];
        $recipients = [];
        foreach (['à activer' => $pending, 'déjà activé' => $verified, 'inconnu' => $unknown] as $label => $email) {
            $response = $this->request('POST', '/api/resend-verification', json_encode(['email' => $email]));
            $this->assertSame(200, $response->getStatusCode(), $label . ' ' . $response->getContent());
            $answers[$label] = [$response->getStatusCode(), json_decode($response->getContent(), true)];
            // Les événements du mailer sont ceux de la dernière requête (le noyau redémarre entre deux) : relevés à chaque tour
            array_push($recipients, ...array_map(fn (Email $m) => $m->getTo()[0]->getAddress(), $this->sentMessages()));
        }
        $this->assertSame([200, ['message' => RegistrationController::RESEND_NEUTRAL_MESSAGE]], $answers['à activer']);
        $this->assertSame($answers['à activer'], $answers['déjà activé'], 'compte déjà activé : réponse identique');
        $this->assertSame($answers['à activer'], $answers['inconnu'], 'courriel inconnu : réponse identique');

        $this->assertSame([$pending], $recipients, 'un seul courriel : le compte à activer');
        $this->assertSame(400, $this->request('POST', '/api/resend-verification', '{}')->getStatusCode(), 'EMAIL_REQUIRED conservé');
    }

    public function testTheResendIsLimitedPerSiteAndEmail(): void
    {
        $email = 'inconnu-' . bin2hex(random_bytes(3)) . '@example.invalid';
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->request('POST', '/api/resend-verification', json_encode(['email' => $email]))->getStatusCode(), "demande $i");
        }
        $blocked = $this->request('POST', '/api/resend-verification', json_encode(['email' => $email]));
        $this->assertSame(429, $blocked->getStatusCode(), 'quatrième demande de l\'heure pour ce courriel : bloquée');
        $body = json_decode($blocked->getContent(), true);
        $this->assertSame('RATE_LIMITED', $body['code']);
        $this->assertGreaterThan(0, $body['retryAfterSeconds']);
        $this->assertSame(200, $this->request('POST', '/api/resend-verification', json_encode(['email' => 'autre-' . bin2hex(random_bytes(3)) . '@example.invalid']))->getStatusCode(), 'autre courriel, même IP : compteur distinct (20 par heure et par site)');
    }

    public function testOtpVerifyAnswersTheSameForAnUnknownEmailAndAWrongCode(): void
    {
        $known = $this->user(verified: true);
        $unknown = 'personne-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $wrongCode = $this->request('POST', '/api/otp-verify', json_encode(['username' => $known, 'otp' => '000000', 'platform' => 'mobile']));
        $unknownEmail = $this->request('POST', '/api/otp-verify', json_encode(['username' => $unknown, 'otp' => '000000', 'platform' => 'mobile']));
        $this->assertSame(401, $wrongCode->getStatusCode());
        $this->assertSame($wrongCode->getContent(), $unknownEmail->getContent(), 'même corps pour un courriel inconnu et un code faux');
        $this->assertSame(['OTP_INVALID', 'Code OTP invalide ou expiré'], [json_decode($unknownEmail->getContent(), true)['code'], json_decode($unknownEmail->getContent(), true)['error']]);
    }

    /** @return Email[] courriels remis au serveur d'envoi pendant le test */
    private function sentMessages(): array
    {
        return array_values(array_map(fn ($event) => $event->getMessage(), array_filter(self::getMailerEvents(), fn ($event) => !$event->isQueued() && $event->getMessage() instanceof Email)));
    }

    private function user(bool $verified): string
    {
        $email = 'enum-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $container = static::getContainer();
        $provider = $container->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Marie')->setLastname('Tremblay')->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified($verified)->setPasswordSet(true);
        if (!$verified) {
            $user->setVerificationToken(bin2hex(random_bytes(16)));
        }
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();

        return $email;
    }

    private function request(string $method, string $path, ?string $body = null): Response
    {
        // IP propre au test : les limites de débit (Redis) survivent d'un lancement à l'autre
        $this->ip ??= '10.84.' . random_int(1, 254) . '.' . random_int(1, 254);
        $this->client->getCookieJar()->clear();
        $this->client->request($method, 'https://' . MV_TEST_TENANT_HOST . $path, [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip,
        ], $body);

        return $this->client->getResponse();
    }
}
