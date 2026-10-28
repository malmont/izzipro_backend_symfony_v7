<?php

namespace App\Services\FormSubmissionService;

use App\Entity\Entreprise;
use App\Entity\FormSubmission;
use App\Entity\Reservation;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\ReservationService\ReservationMailerService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Courriels d'une demande reçue, envoyés APRÈS l'enregistrement (un échec d'envoi ne perd jamais la demande) :
 * - à l'entreprise : Entreprise::formSubmissionsRecipient() (adresse dédiée, sinon courriel de l'entreprise ; jamais une
 *   adresse écrite dans le formulaire), réponses en tableau et référence ; site sans configuration d'envoi : serveur de
 *   la plateforme ;
 * - au visiteur, s'il a donné un courriel : accusé de réception avec la référence ;
 * - cible réservation : les courriels de la réservation (avec .ics) remplacent les deux, référence ajoutée.
 */
final class FormSubmissionNotifier
{
    public function __construct(
        private readonly EmailSenderService $emails,
        private readonly ReservationMailerService $reservationMails,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    public function notify(FormSubmission $submission, ?string $visitorEmail, ?Reservation $reservation, string $locale, string $domain): void
    {
        $recipient = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->formSubmissionsRecipient();
        try {
            if ($reservation !== null) {
                $this->reservationMails->sendReservationEmails($reservation, $locale, $domain, $recipient, $submission->getReference());
                $this->notified($submission);

                return;
            }
            $sent = false;
            if ($recipient !== null) {
                $subject = sprintf('Nouvelle demande : %s (%s)', $submission->getFormName(), $submission->getReference());
                $context = ['submission' => $submission];
                $sent = $this->emails->sendTemplatedEmail($recipient, $subject, 'emails/form_submission_admin.html.twig', $context, $locale, $domain, null, $visitorEmail)
                    || $this->emails->sendPlatformEmail($recipient, $subject, 'emails/form_submission_admin.html.twig', $context, $visitorEmail);
            } else {
                $this->logger->warning('Formulaire à étapes : aucun destinataire (courriel de l\'entreprise absent)', ['reference' => $submission->getReference()]);
            }
            if ($visitorEmail !== null) {
                $this->emails->sendTemplatedEmail($visitorEmail, sprintf('Nous avons bien reçu votre demande (%s)', $submission->getReference()),
                    'emails/form_submission_acknowledgement.html.twig', ['submission' => $submission], $locale, $domain);
            }
            if ($sent) {
                $this->notified($submission);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Formulaire à étapes : notification en échec', ['reference' => $submission->getReference(), 'exception' => $e::class]);
        }
    }

    private function notified(FormSubmission $submission): void
    {
        $submission->setNotifiedAt(new \DateTimeImmutable());
        $this->emProvider->getEntityManager()->flush();
    }
}
