<?php

namespace App\UseCase\LandingAiUseCase;

use App\Services\LandingAiService\LandingAiDesignMdWriter;
use App\Services\LandingAiService\LandingAiException;

/**
 * GET /api/landingpage-ai/design-md?app=landingpage|boutique : la charte du site courant en texte (DESIGN.md), sans
 * appel à l'IA ni crédit. Sert à relire la charte dans l'assistant (designMd) ou dans un autre outil.
 */
class ExportLandingDesignMdUseCase
{
    public const APPS = ['landingpage', 'boutique'];

    public function __construct(private readonly LandingAiDesignMdWriter $writer)
    {
    }

    /** @throws LandingAiException 400 */
    public function execute(?string $app): string
    {
        $app ??= 'landingpage';
        if (!in_array($app, self::APPS, true)) {
            throw LandingAiException::badRequest('Paramètre app invalide : landingpage ou boutique attendu.', [['path' => 'app', 'message' => 'landingpage ou boutique attendu']]);
        }

        return $this->writer->write($app);
    }
}
