<?php

namespace App\Entity;

use App\Repository\GooglePlacesConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GooglePlacesConfigRepository::class)]
class GooglePlacesConfig
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleApiKeyTest = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleApiKeyProdEncrypted = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGoogleApiKeyTest(): ?string
    {
        return $this->googleApiKeyTest;
    }

    public function setGoogleApiKeyTest(?string $key): self
    {
        $this->googleApiKeyTest = $key;
        return $this;
    }

    public function getGoogleApiKeyProd(): ?string
    {
        if (empty($this->googleApiKeyProdEncrypted)) {
            return null;
        }
        $decoded   = sodium_hex2bin($this->googleApiKeyProdEncrypted);
        $decrypted = sodium_crypto_secretbox_open(
            $decoded,
            $this->getNonce(),
            $this->getSecretKey()
        );
        if ($decrypted === false) {
            throw new \RuntimeException('Échec du déchiffrement de la clé prod Google.');
        }
        return $decrypted;
    }

    public function setGoogleApiKeyProd(?string $plain): self
    {
        if (!empty($plain)) {
            $encrypted = sodium_crypto_secretbox(
                $plain,
                $this->getNonce(),
                $this->getSecretKey()
            );
            $this->googleApiKeyProdEncrypted = sodium_bin2hex($encrypted);
        }
        
        return $this;
    }

    private function getNonce(): string
    {
        return substr(hash('sha256', 'google_places_nonce', true), 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    }

    private function getSecretKey(): string
    {
        $key = base64_decode(trim($_ENV['GOOGLE_SECRET_KEY'] ?? ''));
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('La variable GOOGLE_SECRET_KEY doit être une clé 32 octets en base64.');
        }
        return $key;
    }
}
