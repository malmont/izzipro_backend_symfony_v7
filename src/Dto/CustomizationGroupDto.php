<?php
namespace App\Dto;

class CustomizationGroupDto
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public array $values = []
    ) {}
}