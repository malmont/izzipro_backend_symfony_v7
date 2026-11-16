<?php

namespace App\Tests\Functional\Email;

use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\EmailLogoHelper;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\EmailConfigurationService\TenantMailerFactory;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Incident du 16/11/2026 : le code de connexion d'un site NEUF n'est jamais parti. Le site n'a pas de serveur d'envoi à lui : le
 * courriel passe par le serveur de la plateforme, qui refuse d'envoyer au nom d'une adresse qui n'est pas la sienne (553). Désormais,
 * dans ce cas, l'expéditeur est l'adresse de la plateforme, au NOM du site, et l'adresse du site passe en « Répondre à ».
 * (Les sites de test n'ont pas de serveur d'envoi : `tests/bootstrap.php`.)
 */
class PlatformSenderFallbackTest extends KernelTestCase
{
    public function testASiteWithoutItsOwnServerSendsFromThePlatformAddressAndRepliesGoToTheSite(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $c->get(TenantEntityManagerProvider::class)->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $make = fn (?string $dsn): EmailSenderService => new EmailSenderService($c->get(MailerInterface::class), $c->get('twig'), $c->get(EmailConfigurationService::class),
            $c->get(EmailLogoHelper::class), new NullLogger(), $c->get(TenantMailerFactory::class), $dsn);
        $context = ['title' => 'Essai', 'greeting' => 'Bonjour,', 'message' => 'Message', 'button' => 'Ouvrir', 'link' => 'https://example.invalid', 'validity' => '', 'ignore' => '', 'siteLine' => 'Site'];

        $sender = $make('null://plateforme%40arkanoa.test:secret@default');
        $this->assertTrue($sender->sendTemplatedEmail('destinataire@example.invalid', 'Votre code', 'emails/support_agent_invitation.html.twig', $context, 'fr', 'https://example.invalid'));
        $mail = $this->getMailerMessages()[count($this->getMailerMessages()) - 1];
        $this->assertSame('plateforme@arkanoa.test', $mail->getFrom()[0]->getAddress(), 'l\'adresse de la plateforme, que son serveur accepte');
        $this->assertNotSame('', $mail->getFrom()[0]->getName(), 'au nom du site');
        $this->assertSame(['contact@mvtest.test'], array_map(static fn ($a) => $a->getAddress(), $mail->getReplyTo()), 'les réponses vont au site');

        // une réponse déjà choisie par l'appelant est gardée
        $this->assertTrue($sender->sendTemplatedEmail('destinataire@example.invalid', 'Votre code', 'emails/support_agent_invitation.html.twig', $context, 'fr', 'https://example.invalid', null, 'agence@example.invalid'));
        $mail = $this->getMailerMessages()[count($this->getMailerMessages()) - 1];
        $this->assertSame(['agence@example.invalid'], array_map(static fn ($a) => $a->getAddress(), $mail->getReplyTo()));

        // sans compte expéditeur de la plateforme (poste de développement) : rien ne change
        $this->assertTrue($make('null://null')->sendTemplatedEmail('destinataire@example.invalid', 'Votre code', 'emails/support_agent_invitation.html.twig', $context, 'fr', 'https://example.invalid'));
        $mail = $this->getMailerMessages()[count($this->getMailerMessages()) - 1];
        $this->assertSame('contact@mvtest.test', $mail->getFrom()[0]->getAddress());
    }
}
