<?php
namespace App\Services\ShippingService;

use App\Entity\AddressEntreprise;
use App\Repository\EntrepriseRepository;
use LogicException;

class ShipmentAddressBuilder
{
    private EntrepriseRepository $entrepriseRepo;

    public function __construct(EntrepriseRepository $entrepriseRepo)
    {
        $this->entrepriseRepo = $entrepriseRepo;
    }

    /**
     * Construit le « to » à partir du payload reçu.
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
     * Récupère l'Entreprise (la première trouvée) et son AddressEntreprise,
     * puis construit le « from ».
     */
    public function buildFrom(): array
    {
        $ent = $this->entrepriseRepo->findOneBy([]);
        if (!$ent) {
            throw new LogicException('Pas d\'entreprise configurée.');
        }

        /** @var AddressEntreprise|null $addr */
        $addr = $ent->getAddressEntreprise();
        if (!$addr) {
            throw new LogicException('Pas d\'adresse entreprise configurée.');
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
