<?php

namespace App\Services\Agency;

use App\Entity\AddressEntreprise;
use App\Entity\Entreprise;
use App\Services\Platform\SiteDefaultsService;
use App\Services\SiteCharter\SiteCharterService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;

/**
 * Fabrique un site demandé par une agence (lot AG-2, 15/11/2026, `docs/agences-et-import.md` § 8) : la même copie du site de
 * référence que pour tout site neuf (`TenantConnectionManager::createTenant`), puis la fiche du site, les droits « agence », les
 * référentiels automatiques. Tourne dans un worker, avec les accès de la plateforme, jamais ceux de l'agence.
 *
 * « Jamais un site à moitié fait » : si la copie échoue, rien n'existe ; si la fiche ne s'écrit pas, le site que cet appel vient de
 * créer est défait (`discard`). Les droits et les référentiels ne bloquent pas : ce qui manque est signalé dans le résultat, et
 * l'agence peut rouvrir un module.
 */
class AgencySiteBuilder
{
    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AgencyEntitlements $rights,
        private readonly SiteDefaultsService $defaults,
        private readonly SiteCharterService $charter,
        private readonly AgencySiteInviter $inviter,
        private readonly TenantPublicHostResolver $hosts,
        private readonly LoggerInterface $logger,
        private readonly \App\Services\Storefront\StorefrontService $storefront,
    ) {
    }

    /**
     * @param array<string, mixed> $agency
     * @param array<string, mixed> $request la demande (tenant_code, name, admin_email, options)
     * @return array<string, mixed> { modules: { opened, failed }, defaults: list<string>, warnings: list<string> }
     */
    public function build(array $agency, array $request, ?string $by): array
    {
        $code = (string) $request['tenant_code'];
        $dbname = AgencySiteCode::database($code);
        $options = $request['options'];
        $this->tenants->createTenant($code, (string) $request['name'], $dbname); // lève si la copie échoue : rien n'existe alors
        $warnings = [];
        try {
            $this->emProvider->switchTenant($dbname, $code);
            $this->writeSheet($request, $options);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Agences] site %s : fiche non écrite (%s), site défait', $code, $e::class), ['exception' => $e]);
            $this->discard($code);
            throw $e;
        }
        try {
            $this->charter->reset(); // un site neuf part sans brief ni date de pages
        } catch (\Throwable $e) {
            $warnings[] = 'CHARTER_NOT_RESET';
        }
        $features = is_array($options['modules'] ?? null) ? $options['modules'] : AgencyEntitlements::features();
        $modules = $this->rights->open($code, $features, $agency, $by);
        if ($modules['failed'] !== []) {
            $warnings[] = 'MODULES_NOT_OPENED';
        }
        $applied = [];
        try {
            // taxes d'après le pays, expéditeur des courriels, profils des applications ouvertes, essai « Aide IA » de la plateforme
            $applied = $this->defaults->apply('agence ' . $agency['code'])['applied'] ?? [];
        } catch (\Throwable $e) {
            $warnings[] = 'DEFAULTS_NOT_APPLIED';
            $this->logger->error(sprintf('[Agences] site %s : référentiels automatiques non posés (%s)', $code, $e::class));
        }
        // la fiche reflète les droits, même quand aucun n'a été posé (demande sans module) : une fiche neuve ouvre des modules par défaut
        $this->rights->project($code);
        // Lot CONSTRUCTION : l'état de naissance se pose ici, une fois les modules en place (ils sont posés un par un). Avec sa
        // boutique, le site neuf est fermé en entier jusqu'à son ouverture ; sans elle, il est en service.
        if (!$this->storefront->born($code)) {
            $warnings[] = 'STOREFRONT_STATE_NOT_SET';
        }

        return ['modules' => $modules, 'defaults' => $applied, 'warnings' => $warnings];
    }

    /**
     * L'invitation de l'administrateur désigné, une fois le site prêt. Le site neuf devient le site courant.
     *
     * @param array<string, mixed> $agency
     * @param array<string, mixed> $request
     * @param string|null $replaced l'adresse de la personne désignée avant, quand l'agence vient d'en désigner une autre
     * @return array{sent: bool, reason: ?string, expiresAt: ?string}
     */
    public function invite(array $agency, array $request, bool $again = false, ?string $replaced = null): array
    {
        $code = (string) $request['tenant_code'];
        $options = $request['options'];
        try {
            $this->emProvider->switchTenant(AgencySiteCode::database($code), $code);
            $locale = is_string($options['locale'] ?? null) ? $options['locale'] : null;
            $names = [(string) ($options['adminFirstName'] ?? ''), (string) ($options['adminLastName'] ?? '')];
            $replyTo = is_string($agency['contact_email'] ?? null) ? $agency['contact_email'] : null;
            if ($replaced !== null && $replaced !== $request['admin_email']) {
                $this->inviter->withdraw($replaced);
            }

            return $again
                ? $this->inviter->resend((string) $request['admin_email'], $names[0], $names[1], $locale, (string) $agency['name'], $replyTo)
                : $this->inviter->invite((string) $request['admin_email'], $names[0], $names[1], $locale, (string) $agency['name'], $replyTo);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Agences] site %s : invitation de l\'administrateur en échec (%s)', $code, $e::class), ['exception' => $e]);

            return ['sent' => false, 'reason' => 'INVITATION_FAILED', 'expiresAt' => null];
        }
    }

    /** Défait le site que cet appel vient de créer, et les droits déjà posés à son nom */
    public function discard(string $code): void
    {
        // d'abord la garde (« créé par ce processus »), ensuite seulement les droits : rien n'est annulé au nom d'un site existant
        if (!$this->tenants->discardCreatedTenant($code, AgencySiteCode::database($code))) {
            $this->logger->error(sprintf('[Agences] site %s : non défait (il n\'a pas été créé par ce processus)', $code));

            return;
        }
        $this->rights->cancelAll($code);
    }

    /** Le site est livré : le processus ne peut plus le défaire */
    public function delivered(string $code): void
    {
        $this->tenants->forgetCreated(AgencySiteCode::database($code));
    }

    /**
     * La fiche du site : son nom, le courriel de son administrateur comme contact, son adresse publique ; langue, fuseau, devise, pays
     * et province si l'agence les a donnés. Le site de référence n'a pas de fiche : elle est créée ; s'il en portait une, elle est
     * réécrite et rien de l'ancienne ne reste (ni téléphone, ni numéros d'entreprise, ni adresse, ni adresse d'espace d'administration).
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $options
     */
    private function writeSheet(array $request, array $options): void
    {
        $em = $this->emProvider->getEntityManager();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']) ?? new Entreprise();
        $host = $this->hosts->describe((string) $request['tenant_code'])['host'] ?? null;
        $entreprise->setName((string) $request['name'])->setEmail((string) $request['admin_email'])->setTel(null)->setWebsite(is_string($host) && $host !== '' ? 'https://' . $host : null)
            ->setEin(null)->setTvaIntracommunautaire(null)->setAdress(null)->setAdminSpaceUrl(null);
        if (is_string($options['locale'] ?? null)) {
            $entreprise->setLocale($options['locale']);
        }
        if (is_string($options['timezone'] ?? null)) {
            $entreprise->setPosTimezone($options['timezone']);
        }
        if (is_string($options['currency'] ?? null)) {
            $entreprise->setCurrency($options['currency']);
        }
        $address = $entreprise->getAddressEntreprise() ?? new AddressEntreprise();
        $address->setStreet1('')->setStreet2('')->setCity('')->setZip('')->setState((string) ($options['province'] ?? ''))->setCountry((string) ($options['country'] ?? ''))
            ->setPhone('')->setEmail((string) $request['admin_email'])->setEntreprise($entreprise);
        $em->persist($entreprise);
        $em->persist($address);
        $em->flush();
    }
}
