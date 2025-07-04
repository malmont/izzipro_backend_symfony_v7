<?php
// Fichier : /var/www/EcommerceSymfony/src/Service/IiziproJwtService.php

namespace App\Services;

use Firebase\JWT\JWT;

class IiziproJwtService
{
    private string $iiziproPrivateKeyPath;

    public function __construct(string $projectDir)
    {
        $this->iiziproPrivateKeyPath = $projectDir . '/config/secrets/test/iizipro_client.key';
    }

    public function generateForGemsuite(): string
    {
        $privateKey = file_get_contents($this->iiziproPrivateKeyPath);
        $payload = [
            'iss' => 'iizipro-bff',
            'aud' => 'gemsuite-api',
            'iat' => time(),
            'exp' => time() + 120,
            'tnt' => 'iizipro_tenant_main',
        ];

        return JWT::encode($payload, $privateKey, 'RS256');
    }
}