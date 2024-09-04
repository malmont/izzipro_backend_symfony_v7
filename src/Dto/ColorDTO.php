<?php
namespace App\Dto;

class ColorDTO
{
    public string $name;
    public string $codeHexa;

    public function __construct(string $name, string $codeHexa)
    {
        $this->name = $name;
        $this->codeHexa = $codeHexa;
    }

    public static function fromEntity($color): self
    {
        return new self(
            $color->getName(),
            $color->getCodeHexa()
        );
    }
}
