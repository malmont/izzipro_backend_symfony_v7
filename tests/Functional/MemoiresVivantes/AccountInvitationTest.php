<?php

namespace App\Tests\Functional\MemoiresVivantes;

use Symfony\Component\Mime\Email;

/**
 * Invitation d'un utilisateur par un administrateur : le lien d'activation renvoie vers le site frontend qui a fait
 * la demande (avant : vers le stockage des médias, `/bucket-simulator/activer-compte`, une page introuvable) et un
 * e-mail d'invitation part par le serveur d'envoi du site.
 */
class AccountInvitationTest extends BookTypeApiTestCase
{
    public function testInvitationLinkPointsToTheFrontendAndIsEmailed(): void
    {
        self::db()->exec("DELETE FROM \"user\" WHERE email = 'invite@example.invalid'");
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://demo.localhost:3000');

        [$status, $body] = $this->api('POST', '/admin/users', ['email' => 'invite@example.invalid', 'firstName' => 'Nouvelle', 'lastName' => 'Invitée', 'role' => 'ROLE_USER'], 'admin');

        $this->assertSame(201, $status, json_encode($body));
        $this->assertStringStartsWith('https://demo.localhost:3000/activer-compte?token=', $body['user']['activationUrl']);
        $this->assertTrue($body['user']['emailSent']);
        $this->assertStringContainsString('envoyée par e-mail', $body['message']);

        $sent = array_values(array_filter(self::getMailerEvents(), fn ($event) => !$event->isQueued() && $event->getMessage() instanceof Email));
        $this->assertCount(1, $sent);
        /** @var Email $email */
        $email = $sent[0]->getMessage();
        $this->assertSame('invite@example.invalid', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString($body['user']['activationUrl'], (string) $email->getHtmlBody());
        $this->assertStringContainsString('Nouvelle', (string) $email->getHtmlBody());

        // Sans origine connue (appel direct au backend), le domaine du site sert de repli
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://mvtest.backend-strapi.online');
        [, $list] = $this->api('GET', '/admin/users', null, 'admin');
        $invited = array_values(array_filter($list, fn ($u) => $u['email'] === 'invite@example.invalid'))[0];
        $this->assertStringStartsWith('https://' . MV_TEST_TENANT_HOST . '/activer-compte?token=', $invited['activationUrl']);
    }
}
