<?php

namespace App\Services\PosAuditService;

use App\Repository\TenantAppKeyRepository;
use App\Services\TenantConnectionProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Identification du terminal de caisse (chantier C2, 19/10/2026). Un terminal = une clé d'activation « pos ». À
 * l'activation, le serveur rend {id, token} ; token = id.HMAC(site|id) avec le secret de l'application : impossible à
 * forger, rien à stocker, le même à chaque activation de la même clé. Le POS l'envoie dans l'en-tête X-Pos-Terminal.
 * Facultatif pour l'instant (absent ou invalide : terminal inconnu, aucun refus) ; obligatoire avec le chantier S1.
 */
class PosTerminalService
{
    public const HEADER = 'X-Pos-Terminal';
    private array $memo = [];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TenantConnectionProvider $tenant,
        private readonly TenantAppKeyRepository $keys,
        #[Autowire('%kernel.secret%')] private readonly string $secret
    ) {
    }

    public function token(int $keyId, string $tenantCode): string
    {
        return $keyId . '.' . substr(hash_hmac('sha256', $tenantCode . '|' . $keyId, $this->secret), 0, 32);
    }

    /** Terminal de la requête en cours : {id, label} si l'en-tête est valide (signature et clé « pos » non révoquée), sinon null */
    public function current(): ?array
    {
        $header = $this->requestStack->getMainRequest()?->headers->get(self::HEADER);
        $tenantCode = $this->tenant->getTenantCode();
        if (!is_string($header) || $header === '' || !$tenantCode || !preg_match('/^(\d+)\.([0-9a-f]{32})$/', $header, $m)) {
            return null;
        }
        if (array_key_exists($header, $this->memo)) {
            return $this->memo[$header];
        }
        $terminal = null;
        if (hash_equals($this->token((int) $m[1], $tenantCode), $header)) {
            $row = $this->keys->findOneForTenant((int) $m[1], $tenantCode);
            if ($row && $row['app'] === 'pos' && $row['revoked_at'] === null) {
                $terminal = ['id' => (int) $row['id'], 'label' => (string) $row['label']];
            }
        }

        return $this->memo[$header] = $terminal;
    }
}
