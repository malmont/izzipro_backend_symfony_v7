<?php

namespace App\Tests\Functional\Email;

use App\Entity\Reservation;
use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPaymentService;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\ReservationService\ReservationMailerService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * E-mails transactionnels d'un site : rendez-vous, lien de paiement d'un livre, mot de passe oublié. Ils se composent
 * sans erreur et portent le domaine du site courant. Les tests n'envoient rien (tests/bootstrap.php retire le serveur
 * SMTP du site de test : tout part vers null://).
 */
class TransactionalEmailTest extends KernelTestCase
{
    protected function setUp(): void
    {
        static::bootKernel();
        $this->em()->getConnection()->executeStatement("UPDATE entreprise SET email = 'direction@mvtest.test'");
    }

    public function testAppointmentRequestNotifiesClientAndCompany(): void
    {
        static::getContainer()->get(ReservationMailerService::class)->sendReservationEmails($this->reservation(), 'fr', MV_TEST_TENANT_HOST);

        $messages = $this->messages();
        $this->assertCount(2, $messages, 'accusé de réception du client + notification de l\'entreprise');
        $this->assertSame(['client@example.invalid', 'direction@mvtest.test'], array_map(fn (Email $m) => $m->getTo()[0]->getAddress(), $messages));
        $this->assertSame('contact@mvtest.test', $messages[0]->getFrom()[0]->getAddress());
        $this->assertStringContainsString('Séance 1 / 5', (string) $messages[0]->getSubject());
        $this->assertCount(1, $messages[0]->getAttachments(), 'invitation de calendrier jointe');
    }

    public function testConfirmationFromTheAdminUsesTheSiteDomain(): void
    {
        // Depuis EasyAdmin, aucun domaine n'est transmis : avant, celui d'un autre client était utilisé
        static::getContainer()->get(ReservationMailerService::class)->sendStatusChangeEmail($this->reservation(), 'confirmed');

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('confirmé', (string) $messages[0]->getSubject());
        $this->assertStringNotContainsString('lintendantprive', (string) $messages[0]->getHtmlBody());
        $calendar = $messages[0]->getAttachments()[0]->getBody();
        $this->assertMatchesRegularExpression('/^UID:reservation-\d+-\d+@' . preg_quote(MV_TEST_TENANT_HOST, '/') . '\r?$/m', $calendar, 'identifiant du rendez-vous au domaine du site');
    }

    public function testAWritingSessionIsAnnouncedNotRequested(): void
    {
        // Séance programmée par le biographe (liée à un livre) : ce n'est pas une demande à examiner
        $em = $this->em();
        $book = $em->getRepository(Book::class)->findOneBy([]);
        $book->setClientAddress("12 rue des Flamboyants\n97200 Fort-de-France");
        $em->flush();
        $session = $this->reservation()->setBookId((string) $book->getId())->setStepNumber(2)->setTotalSteps(5)->setServiceName('Séance 2 / 5');
        $this->em()->flush();

        static::getContainer()->get(ReservationMailerService::class)->sendReservationEmails($session, 'fr', MV_TEST_TENANT_HOST);
        $messages = $this->messages();
        $this->assertCount(2, $messages);
        $this->assertSame('Votre séance d\'écriture est programmée — Séance 2 / 5', $messages[0]->getSubject());
        $html = (string) $messages[0]->getHtmlBody();
        $this->assertStringContainsString('Comme convenu ensemble', $html);
        $this->assertStringContainsString((string) $book->getTitle(), $html);
        $this->assertStringNotContainsString('demande de réservation', $html);
        $this->assertStringNotContainsString('examiner votre demande', $html);
        $this->assertStringContainsString('Séance programmée — Séance 2 / 5 avec Client Test', (string) $messages[1]->getSubject());
        $calendar = $messages[0]->getAttachments()[0]->getBody();
        $this->assertStringContainsString('SUMMARY:Séance d\'écriture', $calendar);
        $this->assertStringContainsString('LOCATION:12 rue des Flamboyants 97200 Fort-de-France', $calendar, 'lieu de la séance dans l\'agenda');
        $this->assertStringContainsString('Chez vous, 12 rue des Flamboyants', $html);
        $internal = (string) $messages[1]->getHtmlBody();
        $this->assertStringContainsString('https://www.google.com/maps/dir/?api=1&amp;destination=12%20rue%20des%20Flamboyants', $internal, 'itinéraire pour le biographe');

        static::getContainer()->get(ReservationMailerService::class)->sendStatusChangeEmail($session, 'confirmed');
        $confirmed = $this->messages()[2];
        $this->assertSame('Votre séance d\'écriture est confirmée — Séance 2 / 5', $confirmed->getSubject());
        $this->assertStringNotContainsString('Votre demande a été validée', (string) $confirmed->getHtmlBody());

        // Une demande de réservation ordinaire (landing page) garde son texte
        static::getContainer()->get(ReservationMailerService::class)->sendReservationEmails($this->reservation(), 'fr', MV_TEST_TENANT_HOST);
        $request = $this->messages()[3];
        $this->assertStringContainsString('Confirmation de votre demande de réservation', (string) $request->getSubject());
        $this->assertStringContainsString('examiner votre demande', (string) $request->getHtmlBody());
    }

    public function testBookPaymentLinkEmail(): void
    {
        $book = $this->em()->getRepository(Book::class)->findOneBy([]);

        static::getContainer()->get(BookPaymentService::class)->sendPaymentLinkEmail($book, 'client@example.invalid', 'https://checkout.example/session-de-test', 149.0, 'cad', 'Merci pour votre confiance.');

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString((string) $book->getTitle(), (string) $messages[0]->getSubject());
        $html = (string) $messages[0]->getHtmlBody();
        $this->assertStringContainsString('https://checkout.example/session-de-test', $html);
        $this->assertStringContainsString('149', $html);
        $this->assertStringContainsString('Merci pour votre confiance.', $html);
    }

    public function testThePaymentEmailCarriesTheLogo(): void
    {
        // Avant le 01/10/2026 : fichier cherché dans l'ancien dossier public/ et adresse de repli relative, donc image cassée
        $logos = glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/public_bucket/assets/uploads/email-logos/*.png') ?: [];
        if ($logos === []) {
            $this->markTestSkipped('Aucun logo d\'e-mail dans le stockage.');
        }
        $em = $this->em();
        $em->getConnection()->executeStatement('UPDATE email_configuration SET logo = ?', [basename($logos[0])]);
        $em->clear();
        $book = $em->getRepository(Book::class)->findOneBy([]);

        static::getContainer()->get(BookPaymentService::class)->sendPaymentLinkEmail($book, 'client@example.invalid', 'https://checkout.example/x', 49.0, 'eur');

        $message = $this->messages()[0];
        $this->assertStringContainsString('<img src="cid:', (string) $message->getHtmlBody(), 'logo incorporé à l\'e-mail');
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('image', $message->getAttachments()[0]->getMediaType());

        // Logo absent du serveur : adresse publique absolue, jamais un chemin relatif
        $em->getConnection()->executeStatement("UPDATE email_configuration SET logo = 'introuvable.png'");
        $em->clear();
        static::getContainer()->get(BookPaymentService::class)->sendPaymentLinkEmail($em->getRepository(Book::class)->findOneBy([]), 'client@example.invalid', 'https://checkout.example/x', 49.0, 'eur');
        $this->assertMatchesRegularExpression('#<img src="https://[^"]+/assets/uploads/email-logos/introuvable\.png"#', (string) $this->messages()[1]->getHtmlBody());
    }

    public function testAccountEmailsGoThroughTheSiteMailer(): void
    {
        $user = (new User())->setEmail('client@example.invalid')->setFirstname('Client')->setLastname('Test');

        static::getContainer()->get(EmailSenderService::class)->sendTemplatedEmail(
            'client@example.invalid', 'Password Reset', 'reset_password/reset.html.twig',
            ['resetUrl' => 'https://mvtest.test/reinitialiser?token=abc', 'user' => $user], 'fr', 'https://' . MV_TEST_TENANT_HOST
        );

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('contact@mvtest.test', $messages[0]->getFrom()[0]->getAddress(), 'expéditeur du site');
        $this->assertStringContainsString('https://mvtest.test/reinitialiser?token=abc', (string) $messages[0]->getHtmlBody());
    }

    public function testEveryTemplatedEmailFindsTheLogo(): void
    {
        $logos = glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/storage/public_bucket/assets/uploads/email-logos/*.png') ?: [];
        if ($logos === []) {
            $this->markTestSkipped('Aucun logo d\'e-mail dans le stockage.');
        }
        $em = $this->em();
        $sender = static::getContainer()->get(EmailSenderService::class);
        $send = fn (string $domain) => $sender->sendTemplatedEmail('client@example.invalid', 'Password Reset', 'reset_password/reset.html.twig',
            ['resetUrl' => 'https://mvtest.test/r', 'user' => (new User())->setEmail('client@example.invalid')->setFirstname('Client')], 'fr', $domain);

        // Fichier présent sur le serveur : incorporé, quel que soit le « domaine » fourni par l'appelant
        $em->getConnection()->executeStatement('UPDATE email_configuration SET logo = ?', [basename($logos[0])]);
        $em->clear();
        $send('lintendantprive.com');
        $message = $this->messages()[0];
        $this->assertStringContainsString('<img src="cid:', (string) $message->getHtmlBody());
        $this->assertCount(1, $message->getAttachments());

        // Fichier absent : adresse absolue du backend, même avec un hôte de frontend sans schéma (contact) ou une
        // adresse déjà complétée d'un chemin (commandes)
        $em->getConnection()->executeStatement("UPDATE email_configuration SET logo = 'introuvable.png'");
        $em->clear();
        $send('lintendantprive.com');
        $send('https://demo.backend-strapi.online/bucket-simulator/assets/uploads/email-logos/');
        $messages = $this->messages();
        $this->assertMatchesRegularExpression('#<img src="https://[^"/]+/[^"]*assets/uploads/email-logos/introuvable\.png"#', (string) $messages[1]->getHtmlBody());
        $this->assertStringNotContainsString('lintendantprive.com/', (string) $messages[1]->getHtmlBody(), 'le frontend ne sert pas les logos');
        $this->assertSame(1, substr_count((string) $messages[2]->getHtmlBody(), 'assets/uploads/email-logos/'), 'chemin non doublé');
        $this->assertStringContainsString('https://demo.backend-strapi.online/', (string) $messages[2]->getHtmlBody());
    }

    private function reservation(): Reservation
    {
        $reservation = (new Reservation())
            ->setServiceName('Séance 1 / 5')
            ->setReservationDate(new \DateTime('2026-10-15'))
            ->setReservationSlot('14:00 - 16:00')
            ->setClientName('Client Test')
            ->setClientEmail('client@example.invalid')
            ->setClientPhone('0596000000')
            ->setStatus('pending');
        $this->em()->persist($reservation);
        $this->em()->flush();

        return $reservation;
    }

    /** @return Email[] messages confiés au serveur d'envoi pendant le test */
    private function messages(): array
    {
        // Chaque e-mail produit deux événements (mise en file, puis envoi) : on ne garde que l'envoi
        $sent = array_filter(self::getMailerEvents(), fn ($event) => !$event->isQueued() && $event->getMessage() instanceof Email);

        return array_values(array_map(fn ($event) => $event->getMessage(), $sent));
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }
}
