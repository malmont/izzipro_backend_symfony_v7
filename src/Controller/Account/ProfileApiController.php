<?php

namespace App\Controller\Account;

use App\Controller\BaseTenantApiController;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProfileApiController extends BaseTenantApiController
{
    public function __construct(\App\Services\TenantEntityManagerProvider $emProvider, private readonly \App\Services\AccountService\EmailChangeGuard $emailChanges,
        private readonly \App\Services\AccountService\SessionIdentityService $sessions) // session après un changement de courriel (suite de S7)
    {
        parent::__construct($emProvider);
    }

    #[Route('/api/profile', name: 'api_profile_get', methods: ['GET'])]
    public function getProfile(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstname(),
            'lastName' => $user->getLastname(),
            'username' => $user->getUsername(),
            'roles' => $user->getRoles(), // permet au front d'afficher les outils d'administration (ex. éditeur des landing pages)
            'licenseNumber' => $user->getLicenseNumber(),
            'licenseExpirationDate' => $user->getLicenseExpirationDate()?->format('Y-m-d'),
            'phone' => $user->getPrimaryAddress()?->getPhone(), // Attempt to get phone from primary address if user thinks it's there
            // Suite de S7 : changement de courriel confirmé par un code (sinon par le mot de passe actuel) ; mot de passe choisi par le client
            // (faux seulement pour un compte créé par un achat invité, jusqu'à « mot de passe oublié »)
            'otpEnabled' => \App\Services\AccountService\EmailChangeGuard::method($user) === \App\Services\AccountService\EmailChangeGuard::METHOD_OTP,
            'passwordSet' => $user->isPasswordSet(),
        ]);
    }

    #[Route('/api/profile', name: 'api_profile_update', methods: ['PATCH', 'PUT'])]
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $em = $this->emProvider->getEntityManager();

        // S7 (31/10/2026) : un changement RÉEL d'adresse (différente après trim et minuscules) exige une confirmation ; le tunnel
        // de paiement du site renvoie l'adresse actuelle et ne passe pas ici ; adresse vide ou absente : comportement inchangé
        $oldEmail = (string) $user->getEmail();
        // Adresse (relecture du 28/10/2026) : chaîne attendue, comparée après trim et en minuscules comme le site (emailChange.js) ; une adresse
        // identique à la casse ou aux espaces près n'est PAS réécrite (avant, elle l'était telle quelle : connexion ensuite impossible) ;
        // une adresse réellement nouvelle est validée puis enregistrée telle que saisie, sans espaces
        if (array_key_exists('email', $data) && $data['email'] !== null && !is_string($data['email'])) {
            return $this->json(['error' => 'Invalid email'], Response::HTTP_BAD_REQUEST);
        }
        $requestedEmail = is_string($data['email'] ?? null) ? trim($data['email']) : null;
        $emailChanges = $requestedEmail !== null && \App\Services\AccountService\EmailChangeGuard::isChange($oldEmail, $requestedEmail);
        if ($emailChanges && !filter_var($requestedEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email'], Response::HTTP_BAD_REQUEST);
        }
        $method = \App\Services\AccountService\EmailChangeGuard::method($user);
        if ($emailChanges && ($refused = $this->emailChanges->authorize($user, $data, $request)) !== null) {
            return $this->json($refused['body'], $refused['status']);
        }

        if (isset($data['firstName'])) {
            $user->setFirstname($data['firstName']);
        }
        if (isset($data['lastName'])) {
            $user->setLastname($data['lastName']);
        }
        if ($emailChanges) {
            $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $requestedEmail]);
            if ($existingUser && $existingUser->getId() !== $user->getId()) {
                return $this->json(['error' => 'Email already in use'], Response::HTTP_CONFLICT);
            }
            // Nom d'utilisateur égal à l'ancienne adresse : il suit la nouvelle (suite de S7, 27/10/2026), unicité vérifiée. Avant, la
            // comparaison se faisait après le changement d'adresse et ne réussissait jamais : le nom gardait l'ancienne adresse.
            $followsEmail = $user->getUsername() === $oldEmail;
            if ($followsEmail) {
                $taken = $em->getRepository(User::class)->findOneBy(['username' => $requestedEmail]);
                if ($taken && $taken->getId() !== $user->getId()) {
                    return $this->json(['error' => 'Email already in use'], Response::HTTP_CONFLICT);
                }
            }
            $user->setEmail($requestedEmail);
            if ($followsEmail) {
                $user->setUsername($requestedEmail);
            }
        }
        if (isset($data['licenseNumber'])) {
            $user->setLicenseNumber($data['licenseNumber']);
        }
        if (isset($data['licenseExpirationDate'])) {
            try {
                $user->setLicenseExpirationDate(new \DateTime($data['licenseExpirationDate']));
            } catch (\Exception $e) {
                return $this->json(['error' => 'Invalid date format for licenseExpirationDate. Use YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
            }
        }
        
        // If they sent phone, and it's supposedly "already there", 
        // maybe they want to update it on the primary address?
        if (isset($data['phone'])) {
            $primaryAddress = $user->getPrimaryAddress();
            if ($primaryAddress) {
                $primaryAddress->setPhone($data['phone']);
            }
            // Note: If no primary address, we might want to create one or just ignore.
            // But since the user said it's "already there", I'll just do this for now.
        }

        $em->flush();
        if ($emailChanges) {
            $this->emailChanges->notifyChanged($user, $oldEmail, (string) $user->getEmail(), $method, $request);
        }
        // Adresse enregistrée réellement changée (casse comprise) : les jetons, liés au courriel, suivent la nouvelle adresse ; nouveaux
        // jetons rendus sous la forme de la connexion. Adresse inchangée : réponse identique à avant.
        $reissue = $emailChanges; // changement réel seulement : jamais de nouveaux jetons pour une différence de casse ou d'espaces
        $response = new JsonResponse();
        $tokens = [];
        if ($reissue) {
            $this->sessions->transferRefreshTokens($oldEmail, (string) $user->getEmail());
            $tokens = $this->sessions->reissue($user, $request, $response);
        }

        return $this->withTokens($response, $tokens, [
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstname(),
                'lastName' => $user->getLastname(),
                'licenseNumber' => $user->getLicenseNumber(),
                'licenseExpirationDate' => $user->getLicenseExpirationDate()?->format('Y-m-d'),
                'phone' => $user->getPrimaryAddress()?->getPhone(),
            ]
        ]);
    }

    /** Corps de la réponse du profil, avec les nouveaux jetons éventuels (cookies déjà posés sur $response) */
    private function withTokens(JsonResponse $response, array $tokens, array $body): JsonResponse
    {
        return $response->setData($body + $tokens);
    }
}
