<?php

namespace App\Twig;

use App\Entity\User;
use App\Security\Entry\AdminEntrySession;
use App\Security\Entry\ExternalOperator;
use App\Services\TenantPublicHostResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * LE BANDEAU D'UNE ENTRÉE dans EasyAdmin (lot BANDEAU, 18/11/2026, demande du propriétaire) : sur TOUTES les pages d'EasyAdmin pendant une
 * entrée dans un autre site (plateforme, agence, directeur d'un groupe), « Vous êtes dans <nom> (<adresse>) en tant que <qui> », le site
 * d'attache et l'échéance. Posé par la mise en page surchargée (`templates/bundles/EasyAdminBundle/layout.html.twig`). Lecture seule :
 * l'entrée est celle de la session, déjà relue à chaque requête par `AdminEntrySubscriber`.
 */
final class AdminEntryBannerExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly TenantPublicHostResolver $hosts,
        private readonly Security $security,
        private readonly ?\App\Services\TenantConnectionManager $tenants = null,
        private readonly ?\App\Services\Platform\TenantDatabaseConnector $databases = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('admin_entry_banner', $this->banner(...))];
    }

    /** @return array{site: string, siteName: string, host: ?string, as: string, home: string, homeName: string, expiresAt: ?string}|null */
    public function banner(): ?array
    {
        $request = $this->requests->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }
        $entry = AdminEntrySession::get($request->getSession());
        if ($entry === null) {
            return null;
        }
        try {
            $site = $this->hosts->describe($entry['target']);
            $home = $this->hosts->describe($entry['home']);
        } catch (\Throwable) {
            $site = $home = null;
        }
        $user = $this->security->getUser();
        // le compte de la plateforme est un compte du site : « Plateforme, prénom », jamais son adresse ; l'agence et le directeur portent
        // déjà « Agence X, prénom » et « Groupe X, prénom »
        $as = $user instanceof User ? ExternalOperator::platformLabel((string) $user->getFirstname()) : (string) $user?->getUserIdentifier();

        $expires = is_string($entry['expiresAt'] ?? null) ? $entry['expiresAt'] : null;
        $homeTime = null;
        if ($expires !== null) {
            try {
                // retouche du propriétaire (18/11/2026) : l'échéance dite à l'heure du site d'attache, l'UTC en second
                $homeTime = (new \DateTimeImmutable($expires))->setTimezone($this->homeTimezone($entry['home']))->format('H \h i');
            } catch (\Throwable) {
                $homeTime = null;
            }
        }

        return ['site' => $entry['target'], 'siteName' => (string) ($site['name'] ?? $entry['target']), 'host' => isset($site['host']) ? (string) $site['host'] : null,
            'as' => $as, 'home' => $entry['home'], 'homeName' => (string) ($home['name'] ?? $entry['home']), 'expiresAt' => $expires, 'expiresHomeTime' => $homeTime];
    }

    /** Le fuseau du site d'attache, lu dans SA base (la requête est sur le site visé), par la règle de l'horloge du magasin */
    private function homeTimezone(string $home): \DateTimeZone
    {
        $stmt = $this->tenants?->getPdoMaster()->prepare('SELECT dbname FROM tenants WHERE code = ?');
        $stmt?->execute([$home]);
        $db = $stmt?->fetchColumn();
        $row = is_string($db) && $this->databases !== null
            ? ($this->databases->pdo($db)->query('SELECT e.pos_timezone, a.country, a.state FROM entreprise e LEFT JOIN address_entreprise a ON a.entreprise_id = e.id ORDER BY e.id LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [])
            : [];

        return new \DateTimeZone(\App\Services\CaisseService\StoreClock::resolveFrom($row['pos_timezone'] ?? null, $row['country'] ?? null, $row['state'] ?? null)['timezone']);
    }
}
