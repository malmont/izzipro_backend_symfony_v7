<?php

namespace App\Entity;

use App\Repository\SquareConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SquareConfigRepository::class)]
class SquareConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $accessToken = null;

    #[ORM\Column(length: 255)]
    private ?string $applicationId = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $locationId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccessToken(): ?string
    {
        // Vérifier si la valeur est vide avant de déchiffrer
        if (empty($this->accessToken)) {
            return null;  // Pas d'Access Token enregistré
        }

        $decrypted = sodium_crypto_secretbox_open(
            sodium_hex2bin($this->accessToken),
            $this->getNonce(),
            $this->getSecretKey()
        );

        if ($decrypted === false) {
            throw new \Exception('Échec du déchiffrement de l’Access Token.');
        }

        return $decrypted;
    }


    public function setAccessToken(string $accessToken): self
    {
        $this->accessToken = sodium_bin2hex(sodium_crypto_secretbox(
            $accessToken,
            $this->getNonce(),
            $this->getSecretKey()  // 🔐 Utilisation de la clé sécurisée
        ));
        return $this;
    }


     // 📱 Getter pour l'Application ID
     public function getApplicationId(): ?string
     {
         return $this->applicationId;
     }
 
     // 📱 Setter pour l'Application ID
     public function setApplicationId(string $applicationId): self
     {
         $this->applicationId = $applicationId;
 
         return $this;
     }

            // ✅ Getter correctement défini
        public function getIsActive(): ?bool
        {
            return $this->isActive;
        }

        // ✅ Setter avec 1 argument
        public function setIsActive(bool $isActive): self
        {
            $this->isActive = $isActive;
            return $this;
        }
    private function getNonce(): string
    {
        return substr(hash('sha256', 'square_nonce', true), 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    }


    private function getSecretKey(): string
    {
        $decodedKey = base64_decode(trim($_ENV['SQUARE_SECRET_KEY']));

        if (strlen($decodedKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \Exception('La clé de chiffrement doit être exactement de 32 octets.');
        }

        return $decodedKey;
    }

    public function getLocationId(): ?string
    {
        return $this->locationId;
    }

    public function setLocationId(?string $locationId): static
    {
        $this->locationId = $locationId;

        return $this;
    }


}
