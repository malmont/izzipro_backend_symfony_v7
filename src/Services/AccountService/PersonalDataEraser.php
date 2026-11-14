<?php

namespace App\Services\AccountService;

use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionStripeGatewayInterface;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Effacement des données personnelles d'un compte (01/11/2026, exigence des stores pour la boutique mobile ; partagé
 * avec la suppression Mémoires Vivantes). La ligne du compte est gardée, anonymisée, pour la comptabilité ; les
 * commandes et paiements restent, leur adresse de livraison est anonymisée sur place (même ligne `adress`).
 *
 * Effacé ou anonymisé : courriel (`supprime-<id>@compte-supprime.invalid`), nom, mot de passe, rôles, jetons de
 * vérification et de réinitialisation, adresses (nom, rue, téléphone (« - » : colonnes obligatoires), ville, code postal, province ; le pays reste
 * pour les taxes), client Stripe (supprimé chez Stripe), avis (auteur « Client anonyme », lien au compte rompu),
 * inscription à l'infolettre (retirée), réservations (nom, courriel, téléphone), sessions de paiement en cours, PIN
 * de caisse, jetons de rafraîchissement, codes à usage unique, demandes de réinitialisation.
 */
class PersonalDataEraser
{
    public const ANONYMOUS_EMAIL = 'supprime-%d@compte-supprime.invalid';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SubscriptionStripeGatewayInterface $stripe,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function anonymousEmail(int $userId): string
    {
        return sprintf(self::ANONYMOUS_EMAIL, $userId);
    }

    /** @return array{addressesAnonymized: int, reviewsAnonymized: int, reservationsAnonymized: int, newsletterRemoved: bool, stripeCustomerDeleted: bool} */
    public function erase(User $user): array
    {
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $userId = (int) $user->getId();
        $originalEmail = (string) $user->getEmail();
        $anonymous = self::anonymousEmail($userId);

        $stripeDeleted = false;
        if ($customerId = $user->getStripeCustomerId()) {
            try {
                $this->stripe->deleteCustomer($customerId);
                $stripeDeleted = true;
            } catch (\Throwable $e) {
                $this->logger->warning('Suppression de compte : client Stripe non supprimé (' . $e->getMessage() . ')', ['userId' => $userId]);
            }
            $user->setStripeCustomerId(null);
        }

        $user->setPrimaryAddress(null);
        $user->setEmail($anonymous)->setUsername($anonymous)->setFirstname('Compte')->setLastname('supprimé')
            ->setPassword('!' . bin2hex(random_bytes(32)))->setRoles([])->setIsVerified(false)
            ->setVerificationToken(null)->setResetToken(null)->setResetTokenExpiresAt(null)->setOtpEnabled(false)
            ->setLicenseNumber(null)->setLicenseExpirationDate(null)->setDeletedAt(new \DateTimeImmutable());
        $em->flush();

        $counts = ['addresses' => 0, 'reviews' => 0, 'reservations' => 0, 'newsletter' => 0];
        $statements = [
            // adresses anonymisées sur place : les commandes qui les désignent gardent une ligne, sans donnée personnelle
            ['addresses', "UPDATE adress SET firstname = 'Compte', lastname = 'supprimé', fullname = 'Compte supprimé', company = NULL, address = '-', complement = NULL,
                phone = '-', city = '-', codepostal = '-', province = NULL, user_adress_id = NULL WHERE user_adress_id = ?", [$userId]],
            ['reviews', "UPDATE reviews_product SET author_name = 'Client anonyme', user_review_id = NULL WHERE user_review_id = ?", [$userId]],
            ['reservations', "UPDATE reservation SET client_name = 'Client anonyme', client_email = ?, client_phone = '-' WHERE LOWER(client_email) = LOWER(?)", [$anonymous, $originalEmail]],
            ['newsletter', 'DELETE FROM newsletter_subscriber WHERE LOWER(email) = LOWER(?)', [$originalEmail]],
            [null, 'DELETE FROM checkout_session WHERE user_id = ?', [$userId]],
            [null, 'DELETE FROM pos_user_pin WHERE user_id = ?', [$userId]],
            [null, 'DELETE FROM refresh_tokens WHERE username = ?', [$originalEmail]],
            [null, 'DELETE FROM otp_code WHERE user_otp_id = ?', [$userId]],
            [null, 'DELETE FROM reset_password_request WHERE user_id = ?', [$userId]],
            [null, 'DELETE FROM user_app_membership WHERE user_id = ?', [$userId]], // appartenances aux familles d'applications (APPS-MEMBRES M1, 14/11/2026)
        ];
        foreach ($statements as [$key, $sql, $params]) {
            try {
                $affected = (int) $connection->executeStatement($sql, $params);
                if ($key !== null) {
                    $counts[$key] = $affected;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Suppression de compte : nettoyage partiel (' . substr($sql, 0, 40) . '…) : ' . $e->getMessage(), ['userId' => $userId]);
            }
        }
        $em->clear();

        return [
            'addressesAnonymized' => $counts['addresses'],
            'reviewsAnonymized' => $counts['reviews'],
            'reservationsAnonymized' => $counts['reservations'],
            'newsletterRemoved' => $counts['newsletter'] > 0,
            'stripeCustomerDeleted' => $stripeDeleted,
        ];
    }
}
