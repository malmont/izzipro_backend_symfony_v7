<?php

namespace App\MemoiresVivantes\Dto;

class BookInputDto
{
    public ?string $title = null;
    public ?string $subtitle = null;
    public ?string $birthplace = null;
    public ?string $format = null;
    public ?string $type = null;
    /** Type tel que saisi (texte brut), null si absent : permet de distinguer « pas de type » d'un type mal formé ou inconnu */
    public ?string $typeInput = null;
    public ?string $person1FirstName = null;
    public ?string $person1Birthplace = null;
    public ?string $person2FirstName = null;
    public ?string $person2Birthplace = null;
    public ?string $birthYear = null;
    public ?string $deathYear = null;
    public ?string $epigraph = null;
    public ?bool $parentsDeceased = null;
    public ?bool $parentsNotParticipating = null;
    /** Code du catalogue des polices ; un code inconnu est ignoré par les use cases */
    public ?string $font = null;
    /** Nombre de séances d'entretien (1 à 12) ; null : champ absent ou valeur hors limites, donc inchangé */
    public ?int $sessionCount = null;
    /** null : champ absent (inchangé) ; chaîne vide : adresse effacée */
    public ?string $clientAddress = null;
    /** Choix de couverture (17/10/2026) : null = absent (inchangé), chaîne vide = effacé (retour au défaut) ; bgColor hors #rrggbb = ignoré */
    public ?string $coverStyle = null;
    public ?string $bgColor = null;
    public ?string $authorName = null;

    public function __construct(array $data)
    {
        $this->title = self::text($data['title'] ?? null);
        $this->subtitle = self::text($data['subtitle'] ?? null);
        $this->birthplace = self::text($data['birthplace'] ?? null);
        $this->format = self::text($data['format'] ?? null);
        $typeInput = $data['type'] ?? null;
        $this->typeInput = self::text($typeInput);
        // Code bien formé uniquement : l'existence du type (historique ou actif en base) est vérifiée par les use cases
        $this->type = (is_string($typeInput) && preg_match('/^[a-z][a-z0-9_]{0,49}$/', $typeInput)) ? $typeInput : null;
        $this->person1FirstName = self::text($data['person1FirstName'] ?? null);
        $this->person1Birthplace = self::text($data['person1Birthplace'] ?? null);
        $this->person2FirstName = self::text($data['person2FirstName'] ?? null);
        $this->person2Birthplace = self::text($data['person2Birthplace'] ?? null);
        $this->birthYear = self::text($data['birthYear'] ?? $data['birth_year'] ?? null);
        $this->deathYear = self::text($data['deathYear'] ?? $data['death_year'] ?? null);
        $this->epigraph = self::text($data['epigraph'] ?? null);
        $this->font = self::text($data['font'] ?? $data['fontFamily'] ?? null);
        $sessions = $data['sessionCount'] ?? $data['session_count'] ?? null;
        $this->sessionCount = is_numeric($sessions) && (int) $sessions >= 1 && (int) $sessions <= 12 ? (int) $sessions : null;
        $this->clientAddress = self::text($data['clientAddress'] ?? $data['client_address'] ?? null);
        $this->coverStyle = self::text($data['coverStyle'] ?? $data['cover_style'] ?? null);
        $color = self::text($data['bgColor'] ?? $data['bg_color'] ?? null);
        if ($color !== null && $color !== '' && !str_starts_with(trim($color), '#') && ctype_xdigit(trim($color))) {
            $color = '#' . trim($color);
        }
        $this->bgColor = $color === null ? null : ($color === '' || preg_match('/^#[0-9a-fA-F]{6}$/', trim($color)) ? strtolower(trim($color)) : null);
        $this->authorName = self::text($data['authorName'] ?? $data['author_name'] ?? null);

        $deceased = $data['parentsDeceased'] ?? $data['parents_deceased'] ?? $data['parentsNotParticipating'] ?? $data['parents_not_participating'] ?? null;
        $this->parentsDeceased = $deceased !== null ? (bool)$deceased : null;
        $this->parentsNotParticipating = $this->parentsDeceased;
    }

    /** Texte ou nombre seulement : un tableau envoyé à la place d'un texte provoquait une erreur 500 */
    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && !is_bool($value) ? (string) $value : null;
    }
}
