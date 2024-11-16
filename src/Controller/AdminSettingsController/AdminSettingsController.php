<?php

namespace App\Controller\AdminSettingsController;

use App\Entity\AdminSettings;
use App\Repository\AdminSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AdminSettingsController extends AbstractController
{
    private $entityManager;
    private $repository;

    public function __construct(EntityManagerInterface $entityManager, AdminSettingsRepository $repository)
    {
        $this->entityManager = $entityManager;
        $this->repository = $repository;
    }

    /**
     * @Route("/api/admin-settings", name="get_admin_settings", methods={"GET"})
     */
    public function getAdminSettings(): JsonResponse
    {
        $settings = $this->repository->find(1); // On récupère les paramètres (id = 1)

        if (!$settings) {
            return new JsonResponse(['error' => 'Settings not found'], 404);
        }

        return new JsonResponse([
            'navbarComponent' => $settings->getNavbarComponent(),
            'styleChoice' => $settings->getStyleChoice(),
            'themeChoice' => $settings->getThemeChoice(),
            'section1Component' => $settings->getSection1Component(),
            'typeComponentSection1' => $settings->getTypeComponentSection1(),
            'selectTypeProductFetch'  => $settings->getSelectTypeProductFetch(),
            'typeComponentSection2'  => $settings->getTypeComponentSection2(),
            'section2Component' => $settings->getSection2Component(),
            'typeComponentSection3' => $settings->getTypeComponentSection3(),
            'section3Component' => $settings->getSection3Component(),
            'typeComponentSection4' => $settings->getTypeComponentSection4(),
            'section4Component' => $settings->getSection4Component(),
            'selectTypeProductFetchSection2' => $settings->getSelectTypeProductFetchSection2(),
            'selectTypeProductFetchSection3' => $settings->getSelectTypeProductFetchSection3(),
            'selectTypeProductFetchSection4' => $settings->getSelectTypeProductFetchSection4(),
            
        ]);
    }

    /**
     * @Route("/api/admin-settings", name="update_admin_settings", methods={"PUT"})
     */
    public function updateAdminSettings(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $settings = $this->repository->find(1); // On met à jour les paramètres (id = 1)

        if (!$settings) {
            return new JsonResponse(['error' => 'Settings not found'], 404);
        }

        $settings->setNavbarComponent($data['navbarComponent'] ?? $settings->getNavbarComponent());
        $settings->setStyleChoice($data['styleChoice'] ?? $settings->getStyleChoice());
        $settings->setThemeChoice($data['themeChoice'] ?? $settings->getThemeChoice());
        $settings->setSection1Component($data['section1Component'] ?? $settings->getSection1Component());
        $settings->setTypeComponentSection1($data['typeComponentSection1'] ?? $settings->getTypeComponentSection1());
        $settings->setSelectTypeProductFetch($data['selectTypeProductFetch'] ?? $settings->getSelectTypeProductFetch());
        $settings->setTypeComponentSection2($data['typeComponentSection2'] ?? $settings->getTypeComponentSection2());
        $settings->setSection2Component($data['section2Component'] ?? $settings->getSection2Component());
        $settings->setTypeComponentSection3($data['typeComponentSection3'] ?? $settings->getTypeComponentSection3());
        $settings->setSection3Component($data['section3Component'] ?? $settings->getSection3Component());
        $settings->setTypeComponentSection4($data['typeComponentSection4'] ?? $settings->getTypeComponentSection4());
        $settings->setSection4Component($data['section4Component'] ?? $settings->getSection4Component());
        $settings->setSelectTypeProductFetchSection2($data['selectTypeProductFetchSection2'] ?? $settings->getSelectTypeProductFetchSection2());
        $settings->setSelectTypeProductFetchSection3($data['selectTypeProductFetchSection3'] ?? $settings->getSelectTypeProductFetchSection3());
        $settings->setSelectTypeProductFetchSection4($data['selectTypeProductFetchSection4'] ?? $settings->getSelectTypeProductFetchSection4());

        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Settings updated successfully']);
    }
}
