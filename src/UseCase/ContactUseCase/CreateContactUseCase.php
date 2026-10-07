<?php
namespace App\UseCase\ContactUseCase;

use App\Dto\ContactCreateInputDto;
use App\Entity\Contact;
use App\Entity\Entreprise;
use App\Services\ContactService\ContactService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Formulaire de contact public : contrôle champ par champ, puis enregistrement */
class CreateContactUseCase
{
    public function __construct(
        private readonly ContactService $contactService,
        private readonly ValidatorInterface $validator,
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    /**
     * @param string $host site de la demande (nom de repli du sujet généré)
     * @throws ContactFormException
     */
    public function execute(mixed $body, string $host): Contact
    {
        $dto = ContactCreateInputDto::fromPayload($body);
        $errors = $dto->shapeErrors;
        $invalid = array_column($errors, 'field');
        foreach ($this->validator->validate($dto) as $violation) {
            $field = $violation->getPropertyPath();
            if (!in_array($field, $invalid, true)) {
                $errors[] = ['field' => $field, 'message' => (string) $violation->getMessage()];
                $invalid[] = $field;
            }
        }
        if ($errors) {
            throw new ContactFormException($errors);
        }

        $entreprise = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
        $siteName = trim((string) $entreprise?->getName()) ?: $host;

        return $this->contactService->createFromForm($dto, $siteName);
    }
}
