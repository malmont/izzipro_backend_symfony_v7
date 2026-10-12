<?php

namespace App\Services\SiteContentService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refus d'une copie ou d'une suppression du contenu d'exemple : statut, message, détail (fiches en cause) */
final class SiteContentException extends HttpException
{
    public function __construct(int $status, string $message, public readonly array $details = [])
    {
        parent::__construct($status, $message);
    }
}
