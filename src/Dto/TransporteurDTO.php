<?php
namespace App\Dto;

class TransporteurDTO
{
    public string $name;
    public ?string $logo;
    public ?string $contact;

    public function __construct(string $name, ?string $logo, ?string $contact)
    {
        $this->name = $name;
        $this->logo = $logo;
        $this->contact = $contact;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'] ?? '',
            $data['logo'] ?? null,
            $data['contact'] ?? null
        );
    }
}
