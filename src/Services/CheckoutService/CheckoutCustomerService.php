<?php

namespace App\Services\CheckoutService;

use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;

/**
 * Client et adresses d'une commande payée en ligne (reprise de OrderController, 09/10/2026) : adresse d'un client
 * connecté vérifiée ; invité : compte trouvé par son e-mail ou créé (jamais modifié s'il existait), adresses créées.
 */
final class CheckoutCustomerService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{address: Adress} @throws CheckoutException 400, 403, 404 */
    public function customer(User $user, array $data): array
    {
        if (!isset($data['addressId'], $data['items'])) {
            throw new CheckoutException(400, 'Missing required fields (addressId, items)');
        }
        $em = $this->emProvider->getEntityManager();
        $address = $em->getRepository(Adress::class)->find($data['addressId']);
        if (!$address || $address->getUserAdress()?->getId() !== $user->getId()) {
            throw new CheckoutException(403, 'Unauthorized: Invalid address');
        }
        $this->checkCarrier($data);

        return ['address' => $address];
    }

    /**
     * @return array{user: User, address: Adress, isNewUser: bool}
     * @throws CheckoutException 400
     */
    public function guest(array $data): array
    {
        if (!isset($data['guestInfo'], $data['items'], $data['shippingAddress'])) {
            throw new CheckoutException(400, 'Missing required guest fields');
        }
        $guestInfo = $data['guestInfo'];
        $email = is_string($guestInfo['email'] ?? null) ? trim($guestInfo['email']) : '';
        if ($email === '') {
            throw new CheckoutException(400, 'Email is required');
        }
        // Permis exigé seulement pour une location (un article avec réservation)
        foreach (is_array($data['items']) ? $data['items'] : [] as $item) {
            if ((isset($item['booking']) || isset($item['rental'])) && (empty($guestInfo['licenseNumber']) || empty($guestInfo['licenseExpirationDate']))) {
                throw new CheckoutException(400, 'License number and expiration date are required for rental orders.');
            }
        }
        $this->checkCarrier($data);

        $shippingData = $data['shippingAddress'];
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
            $user = (new User())->setEmail($email)->setFirstname($firstName ?: 'Guest')->setLastname($lastName ?: 'User')->setUsername($email)
                ->setPassword(bin2hex(random_bytes(10)))->setIsVerified(true)->setRoles(['ROLE_USER_INTERNET']);
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

    private function checkCarrier(array $data): void
    {
        if (!empty($data['carrierId']) && $this->emProvider->getEntityManager()->getRepository(Carrier::class)->find($data['carrierId']) === null) {
            throw new CheckoutException(404, 'Carrier not found');
        }
        if (isset($data['priceShipping']) && (float) $data['priceShipping'] < 0) {
            throw new CheckoutException(400, 'Frais de livraison invalides');
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
