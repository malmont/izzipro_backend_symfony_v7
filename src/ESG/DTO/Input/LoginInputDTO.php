<?php

namespace App\ESG\DTO\Input;

use Symfony\Component\Validator\Constraints as Assert;

class LoginInputDTO
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email;

    #[Assert\NotBlank]
    public string $password;
}
