<?php
namespace App\Services\ShippingService;

use App\Entity\AddressEntreprise;
use App\Entity\Entreprise; 
use App\Services\TenantEntityManagerProvider; 
use LogicException;

class ShipmentAddressBuilder
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * Cette méthode ne touche pas à la base de données, elle reste INCHANGÉE.
     */
    public function buildTo(array $toAddress): array
    {
        return [
            'name'    => $toAddress['name'],
            'street1' => $toAddress['street1'],
            'street2' => $toAddress['street2']  ?? null,
            'city'    => $toAddress['city'],
            'state'   => $toAddress['province'],
            'zip'     => $toAddress['postal_code'],
            'country' => $toAddress['country'],
            'phone'   => $toAddress['contactNumber'] ?? null,
        ];
    }

    /**
     * Récupère l'Entreprise et son adresse depuis la BDD du tenant.
     */
    public function buildFrom(): array
    {
        // MODIFICATION 3 : On obtient l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $entrepriseRepo = $em->getRepository(Entreprise::class);

        // Cette ligne est maintenant correcte car elle utilise le bon repository
        $ent = $entrepriseRepo->findOneBy([]);
        if (!$ent) {
            throw new LogicException('Pas d\'entreprise configurée pour ce tenant.');
        }

        // Le reste de la logique est INCHANGÉ
        /** @var AddressEntreprise|null $addr */
        $addr = $ent->getAddressEntreprise();
        if (!$addr) {
            throw new LogicException('Pas d\'adresse entreprise configurée pour cette entreprise.');
        }

        return [
            'name'    => $ent->getName(),
            'street1' => $addr->getStreet1(),
            'street2' => $addr->getStreet2(),
            'city'    => $addr->getCity(),
            'state'   => $addr->getState(),
            'zip'     => $addr->getZip(),
            'country' => $addr->getCountry(),
            'phone'   => $addr->getPhone(),
            'email'   => $addr->getEmail(),
        ];
    }
}