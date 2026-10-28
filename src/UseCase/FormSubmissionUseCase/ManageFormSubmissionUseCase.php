<?php

namespace App\UseCase\FormSubmissionUseCase;

use App\Dto\FormSubmissionOutputDto;
use App\Entity\FormSubmission;
use App\Services\FormSubmissionService\FormSubmissionService;

/** GET, PATCH { status } et DELETE /api/form-submissions/{id} */
class ManageFormSubmissionUseCase
{
    public function __construct(private readonly FormSubmissionService $submissions)
    {
    }

    /** @throws FormSubmissionException 404 */
    public function get(int $id): array
    {
        return FormSubmissionOutputDto::detail($this->find($id));
    }

    /** @throws FormSubmissionException 400, 404, 422 */
    public function updateStatus(int $id, mixed $body): array
    {
        if (!is_object($body)) {
            throw new FormSubmissionException(400, 'Objet JSON attendu : { "status": … }.');
        }
        $status = $body->status ?? null;
        if (!is_string($status) || !in_array($status, FormSubmission::STATUSES, true)) {
            throw new FormSubmissionException(422, 'Statut inconnu.', [['path' => 'status', 'message' => 'statut attendu : ' . implode(', ', FormSubmission::STATUSES)]]);
        }
        $submission = $this->find($id);
        $this->submissions->updateStatus($submission, $status);

        return FormSubmissionOutputDto::detail($submission);
    }

    /** @throws FormSubmissionException 404 */
    public function delete(int $id): void
    {
        $this->submissions->delete($this->find($id));
    }

    private function find(int $id): FormSubmission
    {
        return $this->submissions->find($id) ?? throw new FormSubmissionException(404, 'Demande introuvable.');
    }
}
