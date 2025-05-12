<?php

namespace App\Entity;

use App\Repository\EasyPostConfigurationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EasyPostConfigurationRepository::class)]
#[ORM\Table(name: 'easy_post_configuration')]
class EasyPostConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $easypostApiKeySandbox = null;

    // Stocke la clé Prod chiffrée
    #[ORM\Column(name: 'easypost_api_key_prod', length: 255, nullable: true)]
    private ?string $easypostApiKeyProdEncrypted = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEasypostApiKeySandbox(): ?string
    {
        return $this->easypostApiKeySandbox;
    }

    public function setEasypostApiKeySandbox(?string $easypostApiKeySandbox): static
    {
        $this->easypostApiKeySandbox = $easypostApiKeySandbox;

        return $this;
    }

    /**
     * Déchiffre et retourne la clé Prod EasyPost
     */
    public function getEasypostApiKeyProd(): ?string
    {
        if (empty($this->easypostApiKeyProdEncrypted)) {
            return null;
        }

        $decoded = sodium_hex2bin($this->easypostApiKeyProdEncrypted);
        $decrypted = sodium_crypto_secretbox_open(
            $decoded,
            $this->getNonce(),
            $this->getSecretKey()
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Échec du déchiffrement de la clé Prod EasyPost.');
        }

        return $decrypted;
    }

    /**
     * Chiffre et stocke la clé Prod EasyPost
     */
    public function setEasypostApiKeyProd(string $plainKey): static
    {
        $encrypted = sodium_crypto_secretbox(
            $plainKey,
            $this->getNonce(),
            $this->getSecretKey()
        );

        $this->easypostApiKeyProdEncrypted = sodium_bin2hex($encrypted);

        return $this;
    }

    /**
     * Nonce fixe pour le chiffrement/déchiffrement
     */
    private function getNonce(): string
    {
        // Chaîne unique par application
        return substr(hash('sha256', 'easypost_nonce', true), 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    }

    /**
     * Récupère la clé secrète (32 octets) depuis une variable d'environnement
     */
    private function getSecretKey(): string
    {
        $decoded = base64_decode(trim((string) $_ENV['EASYPOST_SECRET_KEY']));
        if (strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('La variable EASYPOST_SECRET_KEY doit être une clé 32 octets encodée en base64.');
        }
        return $decoded;
    }
}
