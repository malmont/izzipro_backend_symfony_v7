<?php

namespace App\Dto;

use App\Entity\FormSubmission;

/**
 * Corps de POST /api/form-submissions : { app, sectionUid, formId, locale, answers, site, fillSeconds }.
 * answers : une clé par name ; texte, date (AAAA-MM-JJ) ou choix en texte ; booléen pour une case ; nombre JSON ;
 * serviceOfferId (nombre) pour optionsSource « services » ; question vide : clé absente ou null.
 * site : champ piège (rempli par un robot) ; fillSeconds : durée de remplissage annoncée par le navigateur.
 */
final class FormSubmissionInputDto
{
    public const MAX_BODY_BYTES = 65536;
    public const MIN_FILL_SECONDS = 8;

    public ?string $app = null;
    public ?string $sectionUid = null;
    public ?string $formId = null;
    public string $locale = 'fr';
    /** @var array<string, mixed> */
    public array $answers = [];
    public bool $trapped = false;
    /** @var list<array{path: string, message: string}> */
    public array $errors = [];

    public static function fromBody(mixed $body): self
    {
        $dto = new self();
        if (!is_object($body)) {
            $dto->errors[] = ['path' => '', 'message' => 'objet JSON attendu'];

            return $dto;
        }
        in_array($body->app ?? null, FormSubmission::APPS, true) ? $dto->app = $body->app : $dto->errors[] = ['path' => 'app', 'message' => 'landingpage ou boutique attendu'];
        foreach (['sectionUid', 'formId'] as $key) {
            $value = $body->$key ?? null;
            is_string($value) && $value !== '' && mb_strlen($value) <= 100 ? $dto->$key = $value : $dto->errors[] = ['path' => $key, 'message' => 'identifiant attendu (100 caractères au plus)'];
        }
        if (isset($body->locale)) {
            is_string($body->locale) && preg_match('/^[a-z]{2}$/', $body->locale) ? $dto->locale = $body->locale : $dto->errors[] = ['path' => 'locale', 'message' => 'code de langue à 2 lettres attendu'];
        }
        if (!is_object($body->answers ?? null)) {
            $dto->errors[] = ['path' => 'answers', 'message' => 'objet des réponses attendu (une clé par question)'];
        } else {
            $dto->answers = get_object_vars($body->answers);
        }
        // anti-robot : champ piège rempli, ou formulaire rempli trop vite (ou sans durée annoncée)
        $fill = $body->fillSeconds ?? null;
        $dto->trapped = (is_string($body->site ?? null) && trim($body->site) !== '') || !(is_int($fill) || is_float($fill)) || $fill < self::MIN_FILL_SECONDS;

        return $dto;
    }
}
