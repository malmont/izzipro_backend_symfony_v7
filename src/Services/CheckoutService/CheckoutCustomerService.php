<?php

namespace App\Services\CheckoutService;

use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;

/**
 * Client et adresses d'une commande payée en ligne (reprise de OrderController, 09/10/2026) : adresse d'un client
 * connecté vérifiée ; invité : compte trouvé par son e-mail ou créé (jamais modifié s'il existait), adresses créées.
 * Retrait en magasin (B20 bis, 08/11/2026) : avec un transporteur `pickup`, l'adresse devient facultative (client : `addressId` absent ;
 * invité : `shippingAddress` absente, `guestInfo` porte alors prénom, nom, courriel et téléphone) ; la commande n'a pas d'adresse de
 * livraison, les taxes suivent la boutique (OrderTaxAddressResolver). Sans transporteur de retrait, l'adresse reste exigée.
 */
final class CheckoutCustomerService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{address: ?Adress} @throws CheckoutException 400, 403, 404 */
    public function customer(User $user, array $data): array
    {
        if (!isset($data['items'])) {
            throw new CheckoutException(400, 'Missing required fields (addressId, items)', [], 'FIELDS_REQUIRED');
        }
        if (!isset($data['addressId'])) {
            if (!$this->isPickup($data)) {
                throw new CheckoutException(400, 'Missing required fields (addressId, items)', [], 'FIELDS_REQUIRED');
            }
            $this->checkCarrier($data);

            return ['address' => null]; // retrait en magasin : commande sans adresse
        }
        $em = $this->emProvider->getEntityManager();
        $address = $em->getRepository(Adress::class)->find($data['addressId']);
        if (!$address || $address->getUserAdress()?->getId() !== $user->getId()) {
            throw new CheckoutException(403, 'Unauthorized: Invalid address', [], 'ADDRESS_FORBIDDEN');
        }
        $this->checkCarrier($data);

        return ['address' => $address];
    }

    /**
     * @return array{user: User, address: ?Adress, isNewUser: bool}
     * @throws CheckoutException 400
     */
    public function guest(array $data): array
    {
        if (!isset($data['guestInfo'], $data['items']) || !is_array($data['guestInfo'])) {
            throw new CheckoutException(400, 'Missing required guest fields', [], 'GUEST_FIELDS_REQUIRED');
        }
        $pickupWithoutAddress = !isset($data['shippingAddress']) && $this->isPickup($data);
        if (!isset($data['shippingAddress']) && !$pickupWithoutAddress) {
            throw new CheckoutException(400, 'Missing required guest fields', [], 'GUEST_FIELDS_REQUIRED');
        }
        $guestInfo = $data['guestInfo'];
        $email = is_string($guestInfo['email'] ?? null) ? trim($guestInfo['email']) : '';
        if ($email === '') {
            throw new CheckoutException(400, 'Email is required', [], 'EMAIL_REQUIRED');
        }
        if ($pickupWithoutAddress && (trim((string) ($guestInfo['firstName'] ?? '')) === '' || trim((string) ($guestInfo['lastName'] ?? '')) === '')) {
            throw new CheckoutException(400, 'Prénom et nom requis pour un retrait en magasin', [['path' => 'guestInfo.firstName', 'message' => 'prénom et nom requis', 'code' => 'GUEST_NAME_REQUIRED']], 'GUEST_NAME_REQUIRED');
        }
        // Permis exigé seulement pour une location (un article avec réservation)
        foreach (is_array($data['items']) ? $data['items'] : [] as $item) {
            if ((isset($item['booking']) || isset($item['rental'])) && (empty($guestInfo['licenseNumber']) || empty($guestInfo['licenseExpirationDate']))) {
                throw new CheckoutException(400, 'License number and expiration date are required for rental orders.', [], 'LICENSE_REQUIRED');
            }
        }
        $this->checkCarrier($data);

        $shippingData = is_array($data['shippingAddress'] ?? null) ? $data['shippingAddress'] : [];
        $firstName = $guestInfo['firstName'] ?? $shippingData['firstname'] ?? null;
        $lastName = $guestInfo['lastName'] ?? $shippingData['lastname'] ?? null;
        if ((!$firstName || !$lastName) && isset($shippingData['fullname'])) {
            $parts = explode(' ', (string) $shippingData['fullname'], 2);
            $firstName ??= $parts[0] ?: null;
            $lastName ??= $parts[1] ?? null;
        }

        $em = $this->emProvider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        // Requête non authentifiée : un compte existant reçoit la commande mais son profil n'est jamais modifié
        $isNewUser = $user === null;
        if ($isNewUser) {
            // Mot de passe que le client n'a jamais choisi : hachage d'un secret aléatoire jeté aussitôt (inutilisable, même pour l'équipe),
            // passwordSet faux (changement de courriel par code ; « mot de passe oublié » pour en choisir un). Avant : 20 caractères en clair.
            $user = (new User())->setEmail($email)->setFirstname($firstName ?: 'Guest')->setLastname($lastName ?: 'User')->setUsername($email)
                ->setPassword(password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT))->setPasswordSet(false)->setIsVerified(true)->setRoles(['ROLE_USER_INTERNET']);
            if (!empty($guestInfo['licenseNumber'])) {
                $user->setLicenseNumber($guestInfo['licenseNumber']);
            }
            if (!empty($guestInfo['licenseExpirationDate'])) {
                $user->setLicenseExpirationDate(new \DateTime($guestInfo['licenseExpirationDate']));
            }
            $em->persist($user);
            $em->flush();
        }

        $phone = $guestInfo['phone'] ?? $shippingData['contactNumber'] ?? null;
        if ($pickupWithoutAddress) { // retrait : aucune adresse créée ; une adresse de facturation fournie est gardée dans le carnet
            if (is_array($data['billingAddress'] ?? null) && $data['billingAddress'] !== []) {
                $billing = self::address($data['billingAddress'], $user, $phone);
                $em->persist($billing);
                $em->flush();
            }

            return ['user' => $user, 'address' => null, 'isNewUser' => $isNewUser];
        }
        $shipping = self::address($shippingData, $user, $phone);
        $billing = self::address($data['billingAddress'] ?? $shippingData, $user, $phone);
        $em->persist($shipping);
        $em->persist($billing);
        $em->flush();
        if ($isNewUser) {
            $user->setPrimaryAddress($shipping);
            $em->flush();
        }

        return ['user' => $user, 'address' => $shipping, 'isNewUser' => $isNewUser];
    }

    /** Permis de conduire du client complété après la commande (seulement s'il manquait) */
    public function completeLicense(User $user, ?string $number, ?string $expiration): void
    {
        $changed = false;
        if (!$user->getLicenseNumber() && $number) {
            $user->setLicenseNumber($number);
            $changed = true;
        }
        if (!$user->getLicenseExpirationDate() && $expiration) {
            $user->setLicenseExpirationDate(new \DateTime($expiration));
            $changed = true;
        }
        if ($changed) {
            $this->emProvider->getEntityManager()->flush();
        }
    }

    /** Transporteur de retrait en magasin (`carrier.kind = pickup`) ? */
    private function isPickup(array $data): bool
    {
        if (empty($data['carrierId'])) {
            return false;
        }
        $carrier = $this->emProvider->getEntityManager()->getRepository(Carrier::class)->find($data['carrierId']);

        return $carrier instanceof Carrier && $carrier->isPickup();
    }

    private function checkCarrier(array $data): void
    {
        if (!empty($data['carrierId']) && $this->emProvider->getEntityManager()->getRepository(Carrier::class)->find($data['carrierId']) === null) {
            throw new CheckoutException(404, 'Carrier not found', [], 'CARRIER_NOT_FOUND');
        }
        if (isset($data['priceShipping']) && (float) $data['priceShipping'] < 0) {
            throw new CheckoutException(400, 'Frais de livraison invalides', [], 'SHIPPING_PRICE_INVALID');
        }
    }

    /** Adresse d'un invité (noms de champs tolérés : ceux du tunnel web et des anciens clients) */
    public static function address(array $data, User $user, ?string $fallbackPhone = null): Adress
    {
        $firstname = $data['firstname'] ?? $data['firstName'] ?? $user->getFirstname() ?? '';
        $lastname = $data['lastname'] ?? $data['lastName'] ?? $user->getLastname() ?? '';
        $province = $data['province'] ?? null;
        $postal = $data['zipCode'] ?? $data['zip'] ?? $data['postalCode'] ?? $data['postcode'] ?? $data['codepostal'] ?? '';
        $country = $data['country'] ?? '';
        // Pays absent (commandes invité du 09/10/2026, sans taxe) : déduit d'une province ou d'un code postal canadien
        if ($country === '' && (\App\Services\OrderService\TaxEngine::normalizeAddress(['province' => $province, 'postalCode' => $postal])['country'] ?? null) === 'CA') {
            $country = 'CA';
        }

        return (new Adress())->setUserAdress($user)->setFirstname($firstname)->setLastname($lastname)
            ->setFullname($data['fullname'] ?? trim($firstname . ' ' . $lastname))->setCompany($data['company'] ?? null)
            ->setAddress($data['addressLineOne'] ?? $data['address'] ?? $data['street'] ?? $data['street1'] ?? '')
            ->setComplement($data['addressLineTwo'] ?? $data['complement'] ?? $data['street2'] ?? null)
            ->setCity((string) ($data['city'] ?? ''))->setProvince($province)->setCodepostal((string) $postal)->setCountry((string) $country)
            ->setPhone((string) ($data['contactNumber'] ?? $data['phone'] ?? $data['phoneNumber'] ?? $data['telephone'] ?? $fallbackPhone ?? ''));
    }
}
