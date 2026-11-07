<?php

namespace App\Controller\Admin;

use App\Services\Booking\RentalSetupService;
use App\Services\Platform\AdminStepUrlResolver;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Page « Location et réservation : réglages et cheminement » (`/admin/location`, 07/11/2026). Administrateurs du site ; habillage EasyAdmin. */
#[IsGranted('ROLE_ADMIN')]
class RentalConsoleAdminController extends AbstractController
{
    public function __construct(private readonly RentalSetupService $setup, private readonly AdminStepUrlResolver $screens, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/location', name: 'admin_location', methods: ['GET'])]
    public function index(): Response
    {
        $overview = $this->setup->overview();
        foreach ($overview['steps'] as $i => $step) {
            $overview['steps'][$i]['url'] = $this->screens->screenUrl($step['screen']);
        }
        foreach ($overview['products'] as $i => $p) {
            $overview['products'][$i]['editUrl'] = $this->urls->unsetAll()->setController(ProductCrudController::class)->setAction(Action::EDIT)->setEntityId($p['id'])->generateUrl();
            $overview['products'][$i]['wizardUrl'] = $this->urls->unsetAll()->setRoute('admin_location_configurer', ['product' => $p['id']])->generateUrl(); // questionnaire pas à pas (08/11/2026)
            $overview['products'][$i]['configUrl'] = $p['configId'] ? $this->urls->unsetAll()->setController(BookingConfigurationCrudController::class)->setAction(Action::EDIT)->setEntityId($p['configId'])->generateUrl() : $this->urls->unsetAll()->setController(BookingConfigurationCrudController::class)->setAction(Action::NEW)->generateUrl();
        }

        return $this->render('admin/location/index.html.twig', ['overview' => $overview, 'packsUrl' => $this->screens->screenUrl('rental_packs'), 'newPackUrl' => $this->urls->unsetAll()->setController(RentalPackCrudController::class)->setAction(Action::NEW)->generateUrl(), 'monSiteUrl' => $this->screens->routeUrl('admin_mon_site'), 'wizardUrl' => $this->urls->unsetAll()->setRoute('admin_location_configurer')->generateUrl()]);
    }
}
