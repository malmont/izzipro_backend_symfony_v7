<?php

namespace App\Services\SharedMedia;

/**
 * Types de fichiers acceptés par la médiathèque de partage, et façon de les servir.
 *
 * Le type réel (détecté sur le contenu) doit correspondre à l'extension : un fichier HTML renommé en .pdf
 * est refusé. À la délivrance, le Content-Type vient de cette table (jamais du fichier) et seuls les formats
 * sans risque de script s'affichent dans le navigateur ; les autres (SVG, bureautique) sont téléchargés.
 */
final class SharedMediaTypes
{
    /** Extension => [type servi, types réels acceptés à l'envoi, affichage dans le navigateur autorisé] */
    private const TYPES = [
        'jpg' => ['image/jpeg', ['image/jpeg'], true],
        'jpeg' => ['image/jpeg', ['image/jpeg'], true],
        'png' => ['image/png', ['image/png'], true],
        'webp' => ['image/webp', ['image/webp'], true],
        'gif' => ['image/gif', ['image/gif'], true],
        // SVG : peut contenir du script, toujours téléchargé (et CSP sandbox côté nginx pour le public)
        'svg' => ['image/svg+xml', ['image/svg+xml', 'text/xml', 'application/xml'], false],
        'pdf' => ['application/pdf', ['application/pdf'], true],
        'txt' => ['text/plain', ['text/plain'], true],
        'mp4' => ['video/mp4', ['video/mp4'], true],
        'webm' => ['video/webm', ['video/webm', 'audio/webm'], true],
        'mov' => ['video/quicktime', ['video/quicktime'], true],
        // Bureautique : les formats Office sont des archives (zip / OLE), détectées de plusieurs façons
        'doc' => ['application/msword', ['application/msword', 'application/CDFV2', 'application/vnd.ms-office', 'application/octet-stream'], false],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], false],
        'xls' => ['application/vnd.ms-excel', ['application/vnd.ms-excel', 'application/CDFV2', 'application/vnd.ms-office', 'application/octet-stream'], false],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], false],
        'ppt' => ['application/vnd.ms-powerpoint', ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/vnd.ms-office', 'application/octet-stream'], false],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'], false],
    ];

    /** @return string[] */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Format attendu par l'option « extensions » de la contrainte File de Symfony (extension => types réels).
     *
     * @return array<string, string[]>
     */
    public static function uploadConstraint(): array
    {
        return array_map(fn (array $t) => $t[1], self::TYPES);
    }

    public static function isAllowed(string $extension, ?string $detectedMimeType): bool
    {
        $extension = strtolower($extension);
        return isset(self::TYPES[$extension]) && in_array((string) $detectedMimeType, self::TYPES[$extension][1], true);
    }

    /** Type servi au navigateur, déduit de l'extension du fichier stocké (jamais du contenu) */
    public static function servedMimeType(string $filename): string
    {
        return self::TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))][0] ?? 'application/octet-stream';
    }

    public static function canDisplayInline(string $filename): bool
    {
        return self::TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))][2] ?? false;
    }
}
