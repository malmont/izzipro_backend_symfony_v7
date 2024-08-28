<?php
namespace App\Controller\AdressController;

use App\Entity\Adress;
use App\Repository\AdressRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/adresses')]
class AdressApiController extends AbstractController
{
    #[Route('/', name: 'get_user_adresses', methods: ['GET'])]
    public function getUserAdresses(AdressRepository $adressRepository): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }

        $adresses = $adressRepository->findBy(['userAdress' => $user]);

        $adressesArray = [];
        foreach ($adresses as $adress) {
            $adressesArray[] = [
                'id' => $adress->getId(),
                'firstname' => $adress->getFirstname(),
                'lastname' => $adress->getLastname(),
                'fullname' => $adress->getFullname(),
                'company' => $adress->getCompany(),
                'addressLineOne' => $adress->getAddress(),
                'addressLineTwo' => $adress->getComplement(),
                'contactNumber' => $adress->getPhone(),
                'city' => $adress->getCity(),
                'zipCode' => $adress->getCodepostal(),
                'country' => $adress->getCountry(),
                'contactNumber' => $adress->getPhone(),
            ];
        }

        return $this->json($adressesArray, Response::HTTP_OK);
    }

    #[Route('', name: 'create_adress', methods: ['POST'])]
    public function createAdress(Request $request, AdressRepository $adressRepository): JsonResponse
    {
        $user = $this->getUser();
    
        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }
    
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
    
        $adress = new Adress();
        $adress->setFirstname($data['firstname'] ?? null);
        $adress->setLastname($data['lastname'] ?? null);
        $adress->setFullname($adress->getLastname() . ' ' . $adress->getFirstname());
        $adress->setCompany($data['company'] ?? null);
        $adress->setAddress($data['addressLineOne'] ?? null);
        $adress->setComplement($data['addressLineTwo'] ?? null);
        $adress->setPhone(isset($data['contactNumber']) ? (int) $data['contactNumber'] : null);
        $adress->setCity($data['city'] ?? null);
        $adress->setCodepostal(isset($data['zipCode']) ? (int) $data['zipCode'] : null);
        $adress->setCountry($data['country'] ?? null);
        $adress->setUserAdress($user);
    
        $adressRepository->save($adress, true);
    
        return $this->json([
                'id' => $adress->getId(),
                'firstname' => $adress->getFirstname(),
                'lastname' => $adress->getLastname(),
                'fullname' => $adress->getFullname(),
                'company' => $adress->getCompany(),
                'addressLineOne' => $adress->getAddress(),
                'addressLineTwo' => $adress->getComplement(),
                'contactNumber' => $adress->getPhone(),
                'city' => $adress->getCity(),
                'zipCode' => $adress->getCodepostal(),
                'country' => $adress->getCountry(),
        ], Response::HTTP_CREATED);
    }
    

    #[Route('/{id}', name: 'edit_adress', methods: ['PUT'])]
    public function editAdress(Request $request, Adress $adress, AdressRepository $adressRepository): JsonResponse
    {
        $user = $this->getUser();

        if (!$user || $adress->getUserAdress() !== $user) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $adress->setFirstname($data['firstname'] ?? $adress->getFirstname());
        $adress->setLastname($data['lastname'] ?? $adress->getLastname());
        $adress->setFullname($adress->getLastname() . ' ' . $adress->getFirstname());
        $adress->setCompany($data['company'] ?? $adress->getCompany());
        $adress->setAddress($data['addressLineOne'] ?? $adress->getAddress());
        $adress->setComplement($data['addressLineTwo'] ?? $adress->getComplement());
        $adress->setPhone(isset($data['contactNumber']) ? (int) $data['contactNumber'] : null);
        $adress->setCity($data['city'] ?? $adress->getCity());
        $adress->setCodepostal(isset($data['zipCode']) ? (int) $data['zipCode'] : null);
        $adress->setCountry($data['country'] ?? $adress->getCountry());

        $adressRepository->save($adress, true);

        return $this->json([
                'id' => $adress->getId(),
                'firstname' => $adress->getFirstname(),
                'lastname' => $adress->getLastname(),
                'fullname' => $adress->getFullname(),
                'company' => $adress->getCompany(),
                'addressLineOne' => $adress->getAddress(),
                'addressLineTwo' => $adress->getComplement(),
                'contactNumber' => $adress->getPhone(),
                'city' => $adress->getCity(),
                'zipCode' => $adress->getCodepostal(),
                'country' => $adress->getCountry(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'delete_adress', methods: ['DELETE'])]
    public function deleteAdress(Adress $adress, AdressRepository $adressRepository): JsonResponse
    {
        $user = $this->getUser();

        if (!$user || $adress->getUserAdress() !== $user) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $adressRepository->remove($adress, true);

        return new JsonResponse(['success' => 'Address deleted successfully'], Response::HTTP_NO_CONTENT);
    }
}
