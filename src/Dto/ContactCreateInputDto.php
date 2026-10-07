<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire de contact public (POST /api/contacts/create et POST /api/contacts) : name, email et message obligatoires,
 * le reste facultatif. « Content » (ancien nom du message) est encore accepté ; tout autre champ est ignoré.
 * Messages d'erreur en français, renvoyés champ par champ ({ field, message }).
 */
class ContactCreateInputDto
{
    public const INDUSTRIES = ['tourisme', 'btp', 'agroalimentaire', 'autre'];
    public const INDUSTRY_LABELS = ['tourisme' => 'Tourisme', 'btp' => 'BTP', 'agroalimentaire' => 'Agroalimentaire', 'autre' => 'Autre'];
    private const TEXT_FIELDS = ['name', 'email', 'message', 'phone', 'subject', 'industry', 'companyName', 'jobFunction'];

    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(min: 2, max: 255, minMessage: 'Le nom doit compter au moins 2 caractères.', maxMessage: 'Le nom doit compter 255 caractères au plus.')]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire.')]
    #[Assert\Email(message: 'L\'adresse e-mail n\'est pas valide.')]
    #[Assert\Length(max: 255, maxMessage: 'L\'adresse e-mail doit compter 255 caractères au plus.')]
    public ?string $email = null;

    #[Assert\NotBlank(message: 'Le message est obligatoire.')]
    #[Assert\Length(max: 10000, maxMessage: 'Le message doit compter 10 000 caractères au plus.')]
    public ?string $message = null;

    #[Assert\Length(min: 5, max: 30, minMessage: 'Le téléphone doit compter au moins 5 caractères.', maxMessage: 'Le téléphone doit compter 30 caractères au plus.')]
    #[Assert\Regex(pattern: '/^[0-9+().\s-]+$/', message: 'Le téléphone ne peut contenir que des chiffres, des espaces et + ( ) . -')]
    public ?string $phone = null;

    #[Assert\Length(max: 255, maxMessage: 'Le sujet doit compter 255 caractères au plus.')]
    public ?string $subject = null;

    #[Assert\Choice(choices: self::INDUSTRIES, message: 'Secteur inconnu : tourisme, btp, agroalimentaire ou autre.')]
    public ?string $industry = null;

    #[Assert\Length(max: 255, maxMessage: 'Le nom de l\'entreprise doit compter 255 caractères au plus.')]
    public ?string $companyName = null;

    #[Assert\Length(max: 255, maxMessage: 'La fonction doit compter 255 caractères au plus.')]
    public ?string $jobFunction = null;

    /** @var list<array{field: string, message: string}> erreurs de forme (valeur qui n'est pas un texte) */
    public array $shapeErrors = [];

    public static function fromPayload(mixed $body): self
    {
        $dto = new self();
        if (!is_array($body)) {
            $dto->shapeErrors[] = ['field' => '', 'message' => 'Objet JSON attendu.'];

            return $dto;
        }
        if (!isset($body['message']) && isset($body['Content'])) {
            $body['message'] = $body['Content'];
        }
        foreach (self::TEXT_FIELDS as $field) {
            $value = $body[$field] ?? null;
            if ($value !== null && !is_string($value) && !is_int($value)) {
                $dto->shapeErrors[] = ['field' => $field, 'message' => 'Texte attendu.'];
                continue;
            }
            $value = trim((string) $value);
            // champ facultatif vide : absent
            $dto->$field = $value === '' ? null : $value;
        }
        if ($dto->industry !== null) {
            $dto->industry = mb_strtolower($dto->industry);
        }

        return $dto;
    }
}
