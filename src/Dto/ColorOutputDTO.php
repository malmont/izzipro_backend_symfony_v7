<?php
namespace App\Dto;

use App\Entity\Color;

class ColorOutputDTO
{
    public int $id;
    public ?string $name;
    public ?string $codeHexa;

    public function __construct(Color $color)
    {
        $this->id = $color->getId();
        $this->name = $color->getName();
        $this->codeHexa = $color->getCodeHexa();
    }
}
