<?php

namespace App\Services\FormSubmissionService;

use App\Entity\Entreprise;
use App\Entity\FormSubmission;
use App\Repository\FormSubmissionRepository;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Demandes reçues par les formulaires à étapes (28/10/2026) : lecture, statut, suppression, export, conservation.
 * Le journal des écritures (content_audit_log) ne reçoit que des métadonnées (statut, référence), jamais les réponses :
 * il est gardé 180 jours, plus longtemps que la demande peut l'être.
 */
final class FormSubmissionService
{
    public const AUDIT_RESOURCE = 'form-submissions';
    public const DEFAULT_TIMEZONE = 'America/Toronto';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ContentAuditRecorder $audit
    ) {
    }

    /** @return array{0: list<FormSubmission>, 1: int} */
    public function search(array $filters, int $page, int $limit): array
    {
        return $this->repository()->search($filters, $page, $limit);
    }

    public function find(int $id): ?FormSubmission
    {
        return $this->repository()->find($id);
    }

    /** @return list<array{formId: string, formName: string, count: int, lastAt: string}> */
    public function forms(): array
    {
        return $this->repository()->forms();
    }

    public function updateStatus(FormSubmission $submission, string $status): void
    {
        $before = $submission->getStatus();
        if ($before === $status) {
            return;
        }
        $submission->setStatus($status);
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(self::AUDIT_RESOURCE, $submission->getId(), 'status', ['reference' => $submission->getReference(), 'status' => $before], ['reference' => $submission->getReference(), 'status' => $status], ['status']);
    }

    public function delete(FormSubmission $submission): void
    {
        $meta = ['reference' => $submission->getReference(), 'formName' => $submission->getFormName(), 'status' => $submission->getStatus(), 'createdAt' => $submission->getCreatedAt()->format(DATE_ATOM)];
        $id = $submission->getId();
        $em = $this->emProvider->getEntityManager();
        $em->remove($submission);
        $em->flush();
        $this->audit->record(self::AUDIT_RESOURCE, $id, 'delete', $meta, null);
    }

    /** @return array{0: list<FormSubmission>, 1: bool} demandes à exporter et vrai si la liste est tronquée à $limit */
    public function forExport(array $filters, int $limit): array
    {
        return [$this->repository()->forExport($filters, $limit), $this->repository()->countFiltered($filters) > $limit];
    }

    /**
     * Supprime les demandes plus anciennes que la durée de conservation du site (Entreprise, 12 mois par défaut).
     * Appelée à chaque envoi et chaque jour par app:form-submissions:purge.
     */
    public function purgeExpired(?\DateTimeImmutable $now = null): int
    {
        $months = $this->entreprise()?->formSubmissionsRetention() ?? Entreprise::FORM_SUBMISSIONS_DEFAULT_RETENTION_MONTHS;

        return $this->repository()->deleteOlderThan(($now ?? new \DateTimeImmutable())->modify(sprintf('-%d months', $months)));
    }

    /** Fuseau du site pour les dates (fuseau de la caisse, America/Toronto par défaut) */
    public function timezone(): \DateTimeZone
    {
        $zone = $this->entreprise()?->getPosTimezone();

        return new \DateTimeZone($zone ?: self::DEFAULT_TIMEZONE);
    }

    private function entreprise(): ?Entreprise
    {
        return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
    }

    private function repository(): FormSubmissionRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(FormSubmission::class);
    }
}
