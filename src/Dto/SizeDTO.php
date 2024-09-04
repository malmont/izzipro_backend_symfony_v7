<?php
namespace App\Dto;

class SizeDTO
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function fromEntity($size): self
    {
        return new self(
            $size->getName()
        );
    }
}
