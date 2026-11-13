<?php

namespace App\Tests\Functional\Email;

use App\Message\SendTenantEmailMessage;
use App\MessageHandler\SendTenantEmailHandler;
use App\Services\EmailConfigurationService\TenantEmailDelivery;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Journal des courriels (lot EMAIL-JOURNAL, 13/11/2026) : le worker n'écrit « [Email] Envoyé » que si le message est réellement parti.
 * Avant, un courriel dont tous les destinataires étaient d'un domaine réservé (comptes de démonstration et d'essai) laissait deux lignes
 * contradictoires, « Non envoyé » puis « Envoyé » : en cherchant pourquoi un code de connexion n'arrivait pas, on lisait un envoi qui
 * n'avait pas eu lieu. La remise est construite ici comme en production (hors environnement de test, où ces adresses sont gardées).
 */
class EmailJournalTest extends KernelTestCase
{
    public function testSentIsOnlyWrittenWhenTheMessageReallyLeft(): void
    {
        self::bootKernel();
        $transport = new class() implements TransportInterface {
            /** @var list<list<string>> destinataires de chaque envoi */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent[] = array_map(fn (Address $a) => $a->getAddress(), $envelope?->getRecipients() ?? []);

                return null;
            }

            public function __toString(): string
            {
                return 'essai://';
            }
        };
        $logger = new BufferingLogger();
        $delivery = new TenantEmailDelivery(static::getContainer()->get(TenantEntityManagerProvider::class), static::getContainer()->get(TenantConnectionProvider::class), $transport, $logger, 'dev');
        $handler = new SendTenantEmailHandler($delivery, $logger);
        $send = function (array $to) use ($handler, $logger): array {
            $email = (new Email())->from('boutique@arkanoa.test')->to(...$to)->subject('Votre code OTP')->text('123456');
            $handler(new SendTenantEmailMessage($email, new Envelope(new Address('boutique@exemple.fr'), array_map(fn (string $a) => new Address($a), $to)), 'demo', null, null));

            return array_map(fn (array $l) => (string) $l[1], $logger->cleanLogs());
        };

        // Tous les destinataires d'un domaine réservé : rien ne part, une seule ligne, et ce n'est pas « Envoyé »
        $lines = $send(['essai@example.invalid']);
        $this->assertSame([], $transport->sent);
        $this->assertSame(['[Email] Non envoyé : destinataire(s) d\'un domaine réservé (démonstration, essai)'], $lines);

        // Un destinataire réel : le message part, « Envoyé » une fois
        $lines = $send(['gerant@boutique-reelle.fr']);
        $this->assertSame([['gerant@boutique-reelle.fr']], $transport->sent);
        $this->assertSame(['[Email] Envoyé : « Votre code OTP » → 1 destinataire(s), site demo, serveur de la plateforme'], $lines);

        // Les deux : seul le destinataire réel est servi, et le journal dit combien ont été écartés
        $transport->sent = [];
        $lines = $send(['gerant@boutique-reelle.fr', 'essai@example.invalid', 'autre@exemple.test']);
        $this->assertSame([['gerant@boutique-reelle.fr']], $transport->sent);
        $this->assertSame(['[Email] Envoyé : « Votre code OTP » → 3 destinataire(s), site demo, serveur de la plateforme — 1 remis, 2 écarté(s) (domaine réservé)'], $lines);

        // La remise rend le nombre de destinataires réellement servis
        $message = fn (array $to) => new SendTenantEmailMessage((new Email())->from('a@b.fr')->to(...$to)->subject('x')->text('y'), new Envelope(new Address('a@b.fr'), array_map(fn (string $a) => new Address($a), $to)), 'demo', null, null);
        $this->assertSame([0, 1, 2], [$delivery->deliver($message(['x@example.com'])), $delivery->deliver($message(['x@reel.fr', 'y@example.org'])), $delivery->deliver($message(['x@reel.fr', 'y@reel.ca']))]);
        $logger->cleanLogs();
    }
}
