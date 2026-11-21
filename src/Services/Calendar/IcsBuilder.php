<?php

namespace App\Services\Calendar;

/**
 * Petit service commun du format iCalendar (RFC 5545) (lot ABO-4, 21/11/2026) : un flux d'agenda et une pièce jointe de courriel,
 * tous deux en `PUBLISH` (une séance annulée : `STATUS:CANCELLED`) ; `REQUEST` et `CANCEL` restent pour un appelant qui donne un ORGANIZER. Heures en UTC (`Z`), textes échappés, lignes repliées à 75 octets.
 * Mémoires Vivantes garde son propre `ReservationMailerService::buildIcsContent` (projet à part, jamais touché).
 */
final class IcsBuilder
{
    public const METHOD_PUBLISH = 'PUBLISH';
    public const METHOD_REQUEST = 'REQUEST';
    public const METHOD_CANCEL = 'CANCEL';

    /**
     * @param list<array{uid: string, start: \DateTimeInterface, end: \DateTimeInterface, summary: string, location?: ?string, description?: ?string, cancelled?: bool, sequence?: int}> $events
     */
    public static function calendar(array $events, string $name, string $method = self::METHOD_PUBLISH): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Izzipro//Abonnements//FR', 'CALSCALE:GREGORIAN', 'METHOD:' . $method, 'X-WR-CALNAME:' . self::text($name)];
        $stamp = self::utc(new \DateTimeImmutable());
        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::text($e['uid']);
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART:' . self::utc($e['start']);
            $lines[] = 'DTEND:' . self::utc($e['end']);
            $lines[] = 'SUMMARY:' . self::text($e['summary']);
            if (($e['location'] ?? null) !== null && $e['location'] !== '') {
                $lines[] = 'LOCATION:' . self::text((string) $e['location']);
            }
            if (($e['description'] ?? null) !== null && $e['description'] !== '') {
                $lines[] = 'DESCRIPTION:' . self::text((string) $e['description']);
            }
            $lines[] = 'SEQUENCE:' . (int) ($e['sequence'] ?? 0);
            $lines[] = 'STATUS:' . (($e['cancelled'] ?? false) ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** Texte d'une propriété : `\`, `;`, `,` échappés, retours à la ligne en `\n`, caractères de contrôle retirés */
    public static function text(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $value);
    }

    /** Une ligne repliée à 75 octets au plus, sans couper un caractère UTF-8 (suite précédée d'une espace) */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $limit = $out === [] ? 75 : 74;
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }

    private static function utc(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }
}
