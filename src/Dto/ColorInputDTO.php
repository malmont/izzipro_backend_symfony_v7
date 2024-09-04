<?php
namespace App\Dto;

class ColorInputDTO
{
    public ?string $name;
    public ?string $codeHexa;

    public function __construct(array $data)
    {
        $this->name = $data['name'] ?? null;
        $this->codeHexa = $data['codeHexa'] ?? null;
    }
}
