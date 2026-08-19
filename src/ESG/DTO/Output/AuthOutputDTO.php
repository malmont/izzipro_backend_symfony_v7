<?php

namespace App\ESG\DTO\Output;

class AuthOutputDTO
{
    public string $token;
    public int $userId;
    public string $email;
    public string $firstName;
    public string $lastName;

    public function __construct(string $token, int $userId, string $email, string $firstName, string $lastName)
    {
        $this->token = $token;
        $this->userId = $userId;
        $this->email = $email;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
    }
}
