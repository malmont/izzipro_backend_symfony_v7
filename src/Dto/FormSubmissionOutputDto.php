<?php

namespace App\Dto;

use App\Entity\FormSubmission;

/** Demande reçue, telle que l'API d'administration la renvoie (liste : sans les réponses complètes) */
final class FormSubmissionOutputDto
{
    public const PREVIEW_ANSWERS = 3;

    public static function summary(FormSubmission $s): array
    {
        return [
            'id' => $s->getId(),
            'reference' => $s->getReference(),
            'formId' => $s->getFormId(),
            'formName' => $s->getFormName(),
            'app' => $s->getApp(),
            'sectionUid' => $s->getSectionUid(),
            'status' => $s->getStatus(),
            'target' => $s->getTarget(),
            'locale' => $s->getLocale(),
            'preview' => array_map(fn (array $a) => ['label' => $a['label'] ?? '', 'display' => $a['display'] ?? ''], array_slice(array_values(array_filter($s->getAnswers(), fn ($a) => ($a['display'] ?? '') !== '')), 0, self::PREVIEW_ANSWERS)),
            'reservationId' => $s->getReservationId(),
            'contactId' => $s->getContactId(),
            'notifiedAt' => $s->getNotifiedAt()?->format(DATE_ATOM),
            'createdAt' => $s->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $s->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    public static function detail(FormSubmission $s): array
    {
        $summary = self::summary($s);
        unset($summary['preview']);

        return $summary + ['formHash' => $s->getFormHash(), 'answers' => $s->getAnswers()];
    }
}
