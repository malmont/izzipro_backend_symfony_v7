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
            'licenseNumber' => $user->getLicenseNumber(),
            'licenseExpirationDate' => $user->getLicenseExpirationDate()?->format('Y-m-d'),
            'phone' => $user->getPrimaryAddress()?->getPhone(), // Attempt to get phone from primary address if user thinks it's there
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

        if (isset($data['firstName'])) {
            $user->setFirstname($data['firstName']);
        }
        if (isset($data['lastName'])) {
            $user->setLastname($data['lastName']);
        }
        if (isset($data['email'])) {
            $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existingUser && $existingUser->getId() !== $user->getId()) {
                return $this->json(['error' => 'Email already in use'], Response::HTTP_CONFLICT);
            }
            $user->setEmail($data['email']);
            // If username is usually the email, update it too
            if ($user->getUsername() === $user->getEmail()) {
                 $user->setUsername($data['email']);
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

        return $this->json([
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
}
