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
        // $translation = $color->getTranslation($locale);
        // TODO: Après la migration, on branchera cette logique.
        // $this->name = $translation ? $translation->getName() : $color->getName();
        $this->id = $color->getId();
        $this->name = $color->getName(); 
        $this->code = $color->getCode(); 
        $this->codeHexa = $color->getCodeHexa();
    }
}