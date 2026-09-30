<?php

namespace App\Services\ReservationService;

use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
use App\Entity\Reservation;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\MapsLink;
use Symfony\Component\Uid\Uuid;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\TenantMailerFactory;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Twig\Environment;

class ReservationMailerService
{
    public function __construct(
        private MailerInterface $defaultMailer,
        private Environment $twig,
        private EmailConfigurationService $emailConfigService,
        private LoggerInterface $logger,
        private TenantEntityManagerProvider $emProvider,
        private TenantMailerFactory $tenantMailerFactory,
        private TenantConnectionManager $tenantManager
    ) {
    }

    /**
     * Séance d'écriture Mémoires Vivantes (programmée par le biographe avec le client, liée à un livre) ou demande
     * de réservation envoyée par un visiteur (landing page) : le texte des e-mails n'est pas le même.
     */
    public static function isWritingSession(Reservation $reservation): bool
    {
        return $reservation->getBookId() !== null || $reservation->getBiographer() !== null || $reservation->getStepNumber() !== null;
    }

    /**
     * Variables propres aux séances : libellé (« Séance 2 sur 5 » ou nom de la prestation), titre du livre, biographe.
     *
     * @return array{sessionLabel: string, bookTitle: ?string, biographerName: ?string}
     */
    private function sessionContext(Reservation $reservation): array
    {
        $label = trim((string) $reservation->getServiceName());
        if ($reservation->getStepNumber() !== null && !preg_match('/\d+\s*(\/|sur)\s*\d+/u', $label)) {
            $label = sprintf('Séance %d%s%s', $reservation->getStepNumber(), $reservation->getTotalSteps() ? ' sur ' . $reservation->getTotalSteps() : '', $label !== '' ? ' — ' . $label : '');
        }

        $book = $this->bookOf($reservation);
        $biographer = $reservation->getBiographer();
        $biographerName = $biographer ? trim($biographer->getFirstname() . ' ' . $biographer->getLastname()) : null;

        return [
            'sessionLabel' => $label !== '' ? $label : 'Séance d\'écriture',
            'bookTitle' => $book?->getTitle(),
            'biographerName' => $biographerName ?: null,
            'clientAddress' => $book?->getClientAddress(),
            'clientAddressMapsUrl' => MapsLink::directionsUrl($book?->getClientAddress()),
        ];
    }

    private function bookOf(Reservation $reservation): ?Book
    {
        if (!$reservation->getBookId()) {
            return null;
        }
        try {
            return $this->emProvider->getEntityManager()->getRepository(Book::class)->find(Uuid::fromString($reservation->getBookId()));
        } catch (\Throwable) {
            // Identifiant hors format ou module absent : le livre n'est pas indispensable à l'e-mail
            return null;
        }
    }

    /** Lieu d'une séance d'écriture : chez le client quand son adresse est connue */
    private function sessionLocation(Reservation $reservation): ?string
    {
        return self::isWritingSession($reservation) ? $this->bookOf($reservation)?->getClientAddress() : null;
    }

    /**
     * Domaine du site courant quand l'appelant n'en fournit pas (changement de statut depuis l'administration) :
     * domaine propre du tenant, sinon son sous-domaine. Avant, tous les sites héritaient du domaine d'un seul client.
     */
    private function defaultDomain(): string
    {
        $code = (string) $this->tenantManager->getCurrentTenantCode();
        try {
            $stmt = $this->tenantManager->getPdoMaster()->prepare('SELECT custom_domain FROM tenants WHERE code = :code');
            $stmt->execute(['code' => $code]);
            $customDomain = trim((string) $stmt->fetchColumn());
            if ($customDomain !== '') {
                return $customDomain;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Domaine du site non résolu pour un e-mail de réservation : ' . $e->getMessage());
        }

        return ($code !== '' ? $code . '.' : '') . ($_ENV['FRONTEND_BASE_DOMAIN'] ?? 'arkanoa-media.com');
    }

    public function sendReservationEmails(Reservation $reservation, string $locale = 'fr', ?string $domain = null): void
    {
        $domain = $domain ?: $this->defaultDomain();

        try {
            $tenantEm = $this->emProvider->getEntityManager();
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);

            // Récupération directe sur la base active du tenant
            $emailConfig = $tenantEm->getRepository(EmailConfiguration::class)->findOneBy([])
                ?: $this->emailConfigService->findOneByLocale($locale);
            $emailConfigTranslation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

            $fromEmail = ($emailConfig && $emailConfig->getFromEmail())
                ? $emailConfig->getFromEmail()
                : ($entreprise && $entreprise->getEmail() ? $entreprise->getEmail() : 'contact@' . $domain);

            $fromName = ($emailConfigTranslation && $emailConfigTranslation->getFromName())
                ? $emailConfigTranslation->getFromName()
                : ($entreprise && $entreprise->getName() ? $entreprise->getName() : 'Service client');

            $signature = $emailConfigTranslation ? $emailConfigTranslation->getSignature() : null;
            $logoUrl = $emailConfig ? $emailConfig->getLogo() : null;

            $googleCalendarUrl = $this->buildGoogleCalendarUrl($reservation, $domain, $entreprise);
            $icsContent = $this->buildIcsContent($reservation, $domain, $fromEmail, $fromName);

            // Choix du mailer (personnalisé SMTP tenant ou défaut)
            $mailer = ($emailConfig && $this->tenantMailerFactory)
                ? $this->tenantMailerFactory->createMailer($emailConfig)
                : $this->defaultMailer;

            // 1. Accusé de réception client
            if ($reservation->getClientEmail()) {
                $this->sendCustomerEmail(
                    $mailer,
                    $reservation,
                    $fromEmail,
                    $fromName,
                    $signature,
                    $logoUrl,
                    $domain,
                    $googleCalendarUrl,
                    $icsContent
                );
            }

            // 2. Notification administrateur
            $adminEmail = $entreprise ? $entreprise->getEmail() : null;
            if ($adminEmail) {
                $this->sendAdminEmail(
                    $mailer,
                    $reservation,
                    $adminEmail,
                    $fromEmail,
                    $fromName,
                    $signature,
                    $logoUrl,
                    $domain,
                    $googleCalendarUrl,
                    $icsContent
                );
            } else {
                $this->logger->warning('Aucun email entreprise trouvé pour la notification admin de réservation.');
            }

        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l\'envoi des emails de réservation: ' . $e->getMessage(), [
                'reservationId' => $reservation->getId(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function sendStatusChangeEmail(Reservation $reservation, string $newStatus, string $locale = 'fr', ?string $domain = null): void
    {
        if (!$reservation->getClientEmail()) {
            return;
        }

        $domain = $domain ?: $this->defaultDomain();

        try {
            $tenantEm = $this->emProvider->getEntityManager();
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);

            $emailConfig = $tenantEm->getRepository(EmailConfiguration::class)->findOneBy([])
                ?: $this->emailConfigService->findOneByLocale($locale);
            $emailConfigTranslation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

            $fromEmail = ($emailConfig && $emailConfig->getFromEmail())
                ? $emailConfig->getFromEmail()
                : ($entreprise && $entreprise->getEmail() ? $entreprise->getEmail() : 'contact@' . $domain);

            $fromName = ($emailConfigTranslation && $emailConfigTranslation->getFromName())
                ? $emailConfigTranslation->getFromName()
                : ($entreprise && $entreprise->getName() ? $entreprise->getName() : 'Service client');

            $signature = $emailConfigTranslation ? $emailConfigTranslation->getSignature() : null;
            $logoUrl = $emailConfig ? $emailConfig->getLogo() : null;

            $googleCalendarUrl = $this->buildGoogleCalendarUrl($reservation, $domain, $entreprise);
            $icsContent = $this->buildIcsContent($reservation, $domain, $fromEmail, $fromName);

            $mailer = ($emailConfig && $this->tenantMailerFactory)
                ? $this->tenantMailerFactory->createMailer($emailConfig)
                : $this->defaultMailer;

            $session = self::isWritingSession($reservation);
            $context = $session ? $this->sessionContext($reservation) : ['sessionLabel' => $reservation->getServiceName()];
            $subject = match ($newStatus) {
                'confirmed' => $session ? sprintf('Votre séance d\'écriture est confirmée — %s', $context['sessionLabel']) : sprintf('✨ Votre rendez-vous est confirmé — %s', $reservation->getServiceName()),
                'cancelled' => $session ? sprintf('Votre séance d\'écriture est annulée — %s', $context['sessionLabel']) : sprintf('Information concernant votre réservation — %s', $reservation->getServiceName()),
                default => $session ? sprintf('Votre séance d\'écriture — %s', $context['sessionLabel']) : sprintf('Mise à jour de votre réservation — %s', $reservation->getServiceName()),
            };

            $html = $this->twig->render($session ? 'emails/memoires_session_status_changed.html.twig' : 'emails/reservation_status_changed.html.twig', $context + [
                'reservation' => $reservation,
                'status' => $newStatus,
                'fromName' => $fromName,
                'signature' => $signature,
                'logoUrl' => $logoUrl,
                'domain' => $domain,
                'googleCalendarUrl' => $googleCalendarUrl,
            ]);

            $email = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($reservation->getClientEmail())
                ->replyTo($fromEmail)
                ->subject($subject)
                ->html($html);

            if ($newStatus === 'confirmed') {
                $email->addPart(new DataPart($icsContent, 'rendez-vous.ics', 'text/calendar; charset=utf-8; method=REQUEST'));
            }

            $mailer->send($email);
            $this->logger->info(sprintf(
                'Email changement de statut (%s) envoyé pour réservation #%d à %s',
                $newStatus,
                $reservation->getId(),
                $reservation->getClientEmail()
            ));

        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l\'envoi de l\'email de changement de statut: ' . $e->getMessage(), [
                'reservationId' => $reservation->getId(),
                'status' => $newStatus,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function sendCustomerEmail(
        MailerInterface $mailer,
        Reservation $reservation,
        string $fromEmail,
        string $fromName,
        ?string $signature,
        ?string $logoUrl,
        string $domain,
        string $googleCalendarUrl,
        string $icsContent
    ): void {
        try {
            $session = self::isWritingSession($reservation);
            $context = $session ? $this->sessionContext($reservation) : [];
            $html = $this->twig->render($session ? 'emails/memoires_session_customer.html.twig' : 'emails/reservation_customer_confirmation.html.twig', $context + [
                'reservation' => $reservation,
                'fromName' => $fromName,
                'signature' => $signature,
                'logoUrl' => $logoUrl,
                'domain' => $domain,
                'googleCalendarUrl' => $googleCalendarUrl,
            ]);

            $email = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($reservation->getClientEmail())
                ->replyTo($fromEmail)
                ->subject($session
                    ? sprintf('Votre séance d\'écriture est programmée — %s', $context['sessionLabel'])
                    : sprintf('Confirmation de votre demande de réservation — %s', $reservation->getServiceName()))
                ->html($html)
                ->addPart(new DataPart($icsContent, 'rendez-vous.ics', 'text/calendar; charset=utf-8; method=REQUEST'));

            $mailer->send($email);
            $this->logger->info(sprintf('Email confirmation client envoyé pour réservation #%d à %s', $reservation->getId(), $reservation->getClientEmail()));
        } catch (\Throwable $e) {
            $this->logger->error('Erreur email client réservation: ' . $e->getMessage(), ['reservationId' => $reservation->getId()]);
        }
    }

    private function sendAdminEmail(
        MailerInterface $mailer,
        Reservation $reservation,
        string $adminEmail,
        string $fromEmail,
        string $fromName,
        ?string $signature,
        ?string $logoUrl,
        string $domain,
        string $googleCalendarUrl,
        string $icsContent
    ): void {
        try {
            $session = self::isWritingSession($reservation);
            $context = $session ? $this->sessionContext($reservation) : [];
            $html = $this->twig->render($session ? 'emails/memoires_session_admin.html.twig' : 'emails/reservation_admin_notification.html.twig', $context + [
                'reservation' => $reservation,
                'fromName' => $fromName,
                'signature' => $signature,
                'logoUrl' => $logoUrl,
                'domain' => $domain,
                'googleCalendarUrl' => $googleCalendarUrl,
            ]);

            $email = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($adminEmail)
                ->replyTo($reservation->getClientEmail())
                ->subject($session
                    ? sprintf('Séance programmée — %s avec %s', $context['sessionLabel'], $reservation->getClientName())
                    : sprintf('🔔 Nouvelle réservation #%d — %s (%s)', $reservation->getId(), $reservation->getServiceName(), $reservation->getClientName()))
                ->html($html)
                ->addPart(new DataPart($icsContent, 'rendez-vous.ics', 'text/calendar; charset=utf-8; method=REQUEST'));

            $mailer->send($email);
            $this->logger->info(sprintf('Notification admin envoyée pour réservation #%d à %s', $reservation->getId(), $adminEmail));
        } catch (\Throwable $e) {
            $this->logger->error('Erreur email admin réservation: ' . $e->getMessage(), ['reservationId' => $reservation->getId()]);
        }
    }

    /**
     * Calcule les dates de début et de fin à partir de reservationDate et reservationSlot.
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function resolveStartEndTimes(Reservation $reservation): array
    {
        $dateStr = $reservation->getReservationDate() ? $reservation->getReservationDate()->format('Y-m-d') : (new \DateTime())->format('Y-m-d');
        $slot = $reservation->getReservationSlot() ?: '';

        // Analyse du créneau horaire (ex: "09:00 - 12:00" ou "14:00 - 18:00" ou "10h00 - 12h00")
        $startHour = '09:00';
        $endHour = '11:00';

        if (preg_match('/(\d{1,2})[h:](\d{2})?\s*-\s*(\d{1,2})[h:](\d{2})?/i', $slot, $matches)) {
            $sh = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $sm = !empty($matches[2]) ? str_pad($matches[2], 2, '0', STR_PAD_LEFT) : '00';
            $eh = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            $em = !empty($matches[4]) ? str_pad($matches[4], 2, '0', STR_PAD_LEFT) : '00';

            $startHour = "{$sh}:{$sm}";
            $endHour = "{$eh}:{$em}";
        } elseif (preg_match('/(\d{1,2})[h:](\d{2})?/i', $slot, $matches)) {
            $sh = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $sm = !empty($matches[2]) ? str_pad($matches[2], 2, '0', STR_PAD_LEFT) : '00';
            $startHour = "{$sh}:{$sm}";
            $endHour = date('H:i', strtotime("{$startHour} +2 hours"));
        }

        $startDate = new \DateTimeImmutable("{$dateStr} {$startHour}:00", new \DateTimeZone('Europe/Paris'));
        $endDate = new \DateTimeImmutable("{$dateStr} {$endHour}:00", new \DateTimeZone('Europe/Paris'));

        return [$startDate, $endDate];
    }

    public function buildGoogleCalendarUrl(Reservation $reservation, string $domain, ?Entreprise $entreprise = null): string
    {
        [$startUtc, $endUtc] = $this->resolveStartEndTimes($reservation);

        // Conversion en UTC pour Google Calendar
        $startStr = $startUtc->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $endStr = $endUtc->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

        $title = self::isWritingSession($reservation) ? sprintf('Séance d\'écriture : %s', $reservation->getServiceName()) : sprintf('Rendez-vous : %s', $reservation->getServiceName());
        $details = sprintf(
            "Prestation : %s\nClient : %s\nTéléphone : %s\nEmail : %s\nNombre de personnes : %d\nNotes : %s\nSite web : https://%s",
            $reservation->getServiceName(),
            $reservation->getClientName(),
            $reservation->getClientPhone(),
            $reservation->getClientEmail(),
            $reservation->getNumberOfGuests(),
            $reservation->getNotes() ?: 'Aucune',
            $domain
        );
        $location = $this->sessionLocation($reservation) ?? ($entreprise && $entreprise->getAdress() ? $entreprise->getAdress() : $domain);

        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => "{$startStr}/{$endStr}",
            'details' => $details,
            'location' => $location,
        ]);
    }

    public function buildIcsContent(Reservation $reservation, string $domain, string $organizerEmail, string $organizerName): string
    {
        [$startDate, $endDate] = $this->resolveStartEndTimes($reservation);

        $dtStamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');
        $dtStart = $startDate->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $dtEnd = $endDate->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

        $uid = sprintf('reservation-%d-%s@%s', $reservation->getId() ?: rand(1000, 9999), time(), $domain);
        $summary = self::isWritingSession($reservation) ? sprintf('Séance d\'écriture : %s', $reservation->getServiceName()) : sprintf('Rendez-vous : %s', $reservation->getServiceName());
        $description = sprintf(
            "Prestation: %s\\nClient: %s\\nTéléphone: %s\\nEmail: %s\\nNotes: %s",
            $reservation->getServiceName(),
            $reservation->getClientName(),
            $reservation->getClientPhone(),
            $reservation->getClientEmail(),
            str_replace(["\r\n", "\n", "\r"], "\\n", $reservation->getNotes() ?: '')
        );

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Arkanoa Media//Reservation//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:REQUEST\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$dtStamp}\r\n";
        $ics .= "DTSTART:{$dtStart}\r\n";
        $ics .= "DTEND:{$dtEnd}\r\n";
        $ics .= "SUMMARY:{$summary}\r\n";
        $ics .= "DESCRIPTION:{$description}\r\n";
        // Lieu de la séance : l'agenda du biographe propose alors l'itinéraire d'un clic
        if ($location = $this->sessionLocation($reservation)) {
            $ics .= sprintf("LOCATION:%s\r\n", addcslashes(preg_replace('/\s+/u', ' ', $location), ';,\\'));
        }
        $ics .= sprintf("ORGANIZER;CN=%s:mailto:%s\r\n", addcslashes($organizerName, ';,'), $organizerEmail);
        if ($reservation->getClientEmail()) {
            $ics .= sprintf("ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;CN=%s:mailto:%s\r\n", addcslashes($reservation->getClientName(), ';,'), $reservation->getClientEmail());
        }
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "TRANSP:OPAQUE\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }
}
