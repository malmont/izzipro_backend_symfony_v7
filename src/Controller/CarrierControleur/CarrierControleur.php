<?php
namespace App\Controller\CarrierControleur;

use App\Entity\Carrier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CarrierControleur extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager )
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/Carrier', name: 'get_Carrier', methods: ['GET'])]
    public function getCarrier(Request $request):JsonResponse
    {
       
        $carriers =$this->entityManager->getRepository(Carrier::class)->findAll();
        $host = $request->getSchemeAndHttpHost() . '/jeesign';
        $carrierData= [];
        foreach ($carriers as $carrier) {
            $carrierData[] = [
                'id' => $carrier->getId(),
                'name' => $carrier->getName(),
                'photo' => $carrier->getPhoto() ? $host . '/assets/uploads/Carrier/' . $carrier->getPhoto() : null,
                'description' => $carrier->getDescription(),
                'price' => $carrier->getPrice(),

            ];
        }

        return $this->json($carrierData, JsonResponse::HTTP_OK);


    }
    

}

