<?php

namespace App\Dto;

use App\Entity\Entreprise;

/** En-tête « magasin » des tickets et rapports de caisse (fiche entreprise) : {name, address, phone, taxNumber} */
class StoreInfoDto
{
    public static function fromEntreprise(?Entreprise $entreprise): ?array
    {
        if (!$entreprise) {
            return null;
        }
        $address = $entreprise->getAddressEntreprise();

        return [
            'name' => $entreprise->getName(),
            'address' => $address
                ? trim(implode(', ', array_filter([$address->getStreet1(), $address->getStreet2(), trim(($address->getZip() ?? '') . ' ' . ($address->getCity() ?? '')), $address->getState(), $address->getCountry()])))
                : $entreprise->getAdress(),
            'phone' => $entreprise->getTel(),
            'taxNumber' => $entreprise->getTvaIntracommunautaire() ?: $entreprise->getEin(),
        ];
    }
}
