<?php

namespace App\Entity;

use App\Repository\OtpCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OtpCodeRepository::class)]
class OtpCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'otpCodes')]
    private ?User $userOtp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiration = null;

    /** Usage du code (01/11/2026) : un code n'est accepté que par le flux qui l'a demandé */
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_EMAIL_CHANGE = 'email_change';
    public const PURPOSE_ACCOUNT_DELETION = 'account_deletion';

    #[ORM\Column(length: 20, options: ['default' => self::PURPOSE_LOGIN])]
    private string $purpose = self::PURPOSE_LOGIN;

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function setPurpose(string $purpose): static
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserOtp(): ?User
    {
        return $this->userOtp;
    }

    public function setUserOtp(?User $userOtp): static
    {
        $this->userOtp = $userOtp;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getExpiration(): ?\DateTimeInterface
    {
        return $this->expiration;
    }

    public function setExpiration(?\DateTimeInterface $expiration): static
    {
        $this->expiration = $expiration;

        return $this;
    }
}
