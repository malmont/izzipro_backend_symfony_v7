<?php

namespace App\Dto;

use App\Services\ReviewService\ReviewException;

/**
 * Avis envoyé par un client : { rating: 1-5, title?: string (120), body: string (2 000) }. Texte en clair : les balises
 * sont retirées, les espaces normalisés (sauts de ligne gardés). En modification, chaque champ est facultatif.
 */
final class ReviewInputDto
{
    public const TITLE_MAX = 120;
    public const BODY_MAX = 2000;

    public function __construct(
        public readonly ?int $rating,
        public readonly ?string $title,
        public readonly ?string $body,
        public readonly bool $hasTitle = true
    ) {
    }

    /** @throws ReviewException 422 */
    public static function fromArray(array $data, bool $partial = false): self
    {
        $errors = [];
        $rating = null;
        if (array_key_exists('rating', $data) || !$partial) {
            $value = $data['rating'] ?? null;
            if (!is_int($value) && !(is_string($value) && ctype_digit($value)) || (int) $value < 1 || (int) $value > 5) {
                $errors[] = ['path' => 'rating', 'message' => 'entier de 1 à 5 attendu'];
            } else {
                $rating = (int) $value;
            }
        }
        $title = null;
        $hasTitle = array_key_exists('title', $data);
        if ($hasTitle && $data['title'] !== null) {
            if (!is_string($data['title'])) {
                $errors[] = ['path' => 'title', 'message' => 'texte attendu'];
            } else {
                $title = self::clean($data['title'], false);
                if (mb_strlen($title) > self::TITLE_MAX) {
                    $errors[] = ['path' => 'title', 'message' => sprintf('%d caractères au plus', self::TITLE_MAX)];
                }
                $title = $title === '' ? null : $title;
            }
        }
        $body = null;
        if (array_key_exists('body', $data) || !$partial) {
            if (!is_string($data['body'] ?? null)) {
                $errors[] = ['path' => 'body', 'message' => 'texte attendu'];
            } else {
                $body = self::clean($data['body'], true);
                if (mb_strlen($body) > self::BODY_MAX) {
                    $errors[] = ['path' => 'body', 'message' => sprintf('%d caractères au plus', self::BODY_MAX)];
                }
            }
        }
        if ($errors) {
            throw new ReviewException(422, $errors[0]['path'] . ' : ' . $errors[0]['message'], $errors);
        }

        return new self($rating, $title, $body, $hasTitle || !$partial);
    }

    /** Texte en clair : balises retirées, entités décodées, espaces normalisés ; sauts de ligne gardés (2 de suite au plus) */
    private static function clean(string $text, bool $multiline): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[^\S\n]+/u', ' ', str_replace(["\r\n", "\r"], "\n", $text));
        $text = $multiline ? (string) preg_replace("/ *\n */u", "\n", $text) : str_replace("\n", ' ', $text);
        $text = (string) preg_replace("/\n{3,}/u", "\n\n", $text);

        return trim($text);
    }
}
