<?php

namespace App\UseCase\StylesUseCase;

use App\Services\StyleService\StyleService;

class GetStylesUseCase
{
    private StyleService $styleService;

    public function __construct(StyleService $styleService)
    {
        $this->styleService = $styleService;
    }

    public function execute(): array
    {
        $styles = $this->styleService->getAllStyles();

        // Convertir les objets Style en tableau
        $stylesArray = [];
        foreach ($styles as $style) {
            $stylesArray[] = [
                'id' => $style->getId(),
                'name' => $style->getName(),
            ];
        }

        return $stylesArray;
    }
}
