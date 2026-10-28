<?php

namespace App\Services\FormSubmissionService;

use App\Dto\ContactCreateInputDto;
use App\Dto\ReservationInputDto;
use App\Entity\Contact;
use App\Entity\Reservation;
use App\Entity\ServiceOffer;
use App\Services\ContactService\ContactService;
use App\Services\ReservationService\ReservationService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Cibles d'un formulaire (formTarget) : une réservation (agenda, créneaux pris, courriels avec .ics) ou un contact,
 * créés à partir des rôles des questions (fieldRole), dans la transaction de la demande. Les réponses sans rôle vont
 * dans les remarques (réservation) ou le message (contact).
 */
final class FormTargetWriter
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ContactService $contacts,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $values valeurs normalisées par name
     * @param list<array> $answers réponses enregistrées (libellé, affichage)
     * @throws \DomainException créneau déjà pris
     */
    public function reservation(FormDefinition $form, array $values, array $answers, string $host): ?Reservation
    {
        $value = fn (string $role) => ($field = $form->byRole($role)) !== null ? ($values[$field['name']] ?? null) : null;
        $date = $value('date');
        $email = $value('email');
        if (!is_string($date) || !is_string($email)) {
            // formulaire sans question date : la demande est gardée, l'agenda n'est pas touché
            $this->logger->warning('Formulaire à étapes : réservation non créée (question date ou courriel absente)', ['formId' => $form->formId]);

            return null;
        }

        $dto = new ReservationInputDto();
        [$dto->service_id, $dto->service_name] = $this->service($form, $value('service'));
        $dto->reservation_date = $date;
        $slot = $value('slot');
        $dto->reservation_slot = is_string($slot) ? self::normalizeSlot($slot) : null;
        $dto->client_email = $email;
        $dto->client_name = is_string($value('name')) ? $value('name') : $email;
        $dto->client_phone = is_string($value('phone')) ? mb_substr($value('phone'), 0, 50) : '';
        $guests = $value('guests');
        $dto->number_of_guests = is_int($guests) && $guests >= 1 ? $guests : 1;
        $dto->notes = $this->otherAnswers($form, $answers, ['service', 'date', 'slot', 'name', 'phone', 'email', 'guests'], $value('notes'));

        return $this->reservations->createReservation($dto, $host);
    }

    /** @param array<string, mixed> $values */
    public function contact(FormDefinition $form, array $values, array $answers, string $siteName): ?Contact
    {
        $value = fn (string $role) => ($field = $form->byRole($role)) !== null ? ($values[$field['name']] ?? null) : null;
        $email = $value('email');
        if (!is_string($email)) {
            return null;
        }
        $dto = new ContactCreateInputDto();
        $dto->email = $email;
        $dto->name = is_string($value('name')) && mb_strlen($value('name')) >= 2 ? mb_substr($value('name'), 0, 255) : $email;
        $dto->phone = is_string($value('phone')) ? mb_substr($value('phone'), 0, 30) : null;
        $dto->subject = $form->formName;
        $dto->message = $this->otherAnswers($form, $answers, ['name', 'email', 'phone'], $value('notes')) ?: $form->formName;

        return $this->contacts->createFromForm($dto, $siteName);
    }

    /**
     * Créneau comparé tel quel par le verrou des réservations : espaces regroupés et tirets longs ramenés au trait
     * d'union (« 09:00 – 12:00 » et « 09:00 - 12:00 » désignent le même créneau).
     */
    public static function normalizeSlot(string $slot): string
    {
        return mb_substr(trim((string) preg_replace(['/[\x{2012}-\x{2015}\x{2212}]/u', '/\s+/u'], ['-', ' '], $slot)), 0, 100);
    }

    /** @return array{0: ?string, 1: string} identifiant historique (slug) et nom de la prestation */
    private function service(FormDefinition $form, mixed $value): array
    {
        $field = $form->byRole('service');
        if ($field !== null && $field['optionsSource'] === 'services' && is_int($value)) {
            $offer = $this->emProvider->getEntityManager()->getRepository(ServiceOffer::class)->find($value);
            if ($offer instanceof ServiceOffer) {
                $slug = (new \Symfony\Component\String\Slugger\AsciiSlugger())->slug((string) $offer->getTitre())->lower()->toString();

                return [$slug ?: (string) $offer->getId(), (string) $offer->getTitre()];
            }
        }
        if ($field !== null && $value !== null) {
            foreach ($field['options'] as $option) {
                if ($option['value'] === (string) $value) {
                    return [mb_substr($option['value'], 0, 100), mb_substr($option['label'], 0, 255)];
                }
            }
        }

        return [null, mb_substr($form->formName, 0, 255)];
    }

    /** Remarques : la réponse « notes », puis chaque réponse sans rôle repris ailleurs (« Libellé : réponse ») */
    private function otherAnswers(FormDefinition $form, array $answers, array $mappedRoles, mixed $notes): ?string
    {
        $roles = array_column($form->fields, 'fieldRole', 'name');
        $lines = is_string($notes) && $notes !== '' ? [$notes] : [];
        foreach ($answers as $answer) {
            $role = $roles[$answer['name']] ?? null;
            if ($answer['display'] !== '' && $role !== 'notes' && !in_array($role, $mappedRoles, true)) {
                $lines[] = sprintf('%s : %s', $answer['label'], $answer['display']);
            }
        }

        return $lines ? implode("\n", $lines) : null;
    }
}
