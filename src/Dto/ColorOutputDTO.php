<?php
namespace App\Dto;

use App\Entity\Color;

class ColorOutputDTO
{
    public int $id;
    public ?string $name;
    public ?string $code; 
    public ?string $codeHexa;

    public function __construct(Color $color, string $locale)
    {
        $translation = $color->getTranslation($locale);

        $this->id = $color->getId();
        $this->name = $translation?->getName() ?? $color->getName(); 
        $this->code = $color->getCode(); 
        $this->codeHexa = $color->getCodeHexa();
    }
}