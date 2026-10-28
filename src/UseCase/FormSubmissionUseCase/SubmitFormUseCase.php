<?php

namespace App\UseCase\FormSubmissionUseCase;

use App\Dto\FormSubmissionInputDto;
use App\Entity\Contact;
use App\Entity\Entreprise;
use App\Entity\FormSubmission;
use App\Entity\Reservation;
use App\Services\FormSubmissionService\FormAnswerValidator;
use App\Services\FormSubmissionService\FormDefinitionLocator;
use App\Services\FormSubmissionService\FormSubmissionNotifier;
use App\Services\FormSubmissionService\FormSubmissionReferenceGenerator;
use App\Services\FormSubmissionService\FormSubmissionService;
use App\Services\FormSubmissionService\FormTargetWriter;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * POST /api/form-submissions (public, limite par IP) : formulaire retrouvé dans les réglages enregistrés, chaque réponse
 * revalidée, demande enregistrée avec sa référence (et la réservation ou le contact de la cible, dans la même
 * transaction), puis purge des demandes expirées et notifications. Envoi écarté par l'anti-robot : faux 201 avec une
 * référence de même forme, rien d'enregistré ni d'envoyé.
 */
class SubmitFormUseCase
{
    public function __construct(
        private readonly FormDefinitionLocator $locator,
        private readonly FormAnswerValidator $validator,
        private readonly FormSubmissionReferenceGenerator $references,
        private readonly FormTargetWriter $targets,
        private readonly FormSubmissionNotifier $notifier,
        private readonly FormSubmissionService $submissions,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{reference: string}
     * @throws FormSubmissionException 400, 404 form_not_found, 409 form_changed | slot_taken, 422
     */
    public function execute(mixed $body, string $host): array
    {
        $dto = FormSubmissionInputDto::fromBody($body);
        if ($dto->errors) {
            throw new FormSubmissionException(400, 'Requête invalide : ' . ($dto->errors[0]['path'] ?: 'corps') . ' : ' . $dto->errors[0]['message'], $dto->errors);
        }
        $zone = $this->submissions->timezone();
        if ($dto->trapped) {
            return ['reference' => $this->references->decoy($zone)];
        }

        $form = $this->locator->find((string) $dto->app, (string) $dto->sectionUid, (string) $dto->formId, $dto->locale);
        if ($form === null) {
            throw new FormSubmissionException(404, 'Ce formulaire n\'existe plus sur le site : rechargez la page.', [], 'form_not_found');
        }
        $unknown = array_values(array_diff(array_keys($dto->answers), array_column($form->fields, 'name')));
        if ($unknown) {
            throw new FormSubmissionException(409, 'Le formulaire a changé pendant votre saisie : rechargez la page, vos réponses seront reprises.', array_map(fn ($n) => ['path' => "answers.$n", 'message' => 'question inconnue'], $unknown), 'form_changed');
        }
        [$answers, $errors, $values] = $this->validator->validate($form, $dto->answers, $zone);
        if ($errors) {
            throw new FormSubmissionException(422, 'Formulaire incomplet', $errors);
        }

        $email = ($field = $form->byRole('email')) !== null && is_string($values[$field['name']] ?? null) ? $values[$field['name']] : null;
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $reservation = null;
        $connection->beginTransaction();
        try {
            $submission = (new FormSubmission($this->references->generate($zone), $form->app, $form->sectionUid, $form->formId))
                ->setFormName($form->formName)->setFormHash($form->hash)->setAnswers($answers)->setLocale($dto->locale)->setTarget($form->target);
            if ($form->target === 'reservation') {
                $reservation = $this->targets->reservation($form, $values, $answers, $host);
                $submission->setReservationId($reservation?->getId());
            } elseif ($form->target === 'contact') {
                $siteName = trim((string) $em->getRepository(Entreprise::class)->findOneBy([])?->getName()) ?: $host;
                $contact = $this->targets->contact($form, $values, $answers, $siteName);
                $submission->setContactId($contact instanceof Contact ? $contact->getId() : null);
            }
            $em->persist($submission);
            $em->flush();
            $connection->commit();
        } catch (\DomainException $e) {
            $this->rollBack($connection);
            throw new FormSubmissionException(409, $e->getMessage(), [], 'slot_taken');
        } catch (\Throwable $e) {
            $this->rollBack($connection);
            throw $e;
        }

        try {
            $this->submissions->purgeExpired();
        } catch (\Throwable $e) {
            $this->logger->error('Formulaire à étapes : purge en échec', ['exception' => $e::class]);
        }
        $this->notifier->notify($submission, $email, $reservation instanceof Reservation ? $reservation : null, $dto->locale, $host);

        return ['reference' => $submission->getReference()];
    }

    private function rollBack(\Doctrine\DBAL\Connection $connection): void
    {
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->emProvider->getEntityManager()->clear();
    }
}
