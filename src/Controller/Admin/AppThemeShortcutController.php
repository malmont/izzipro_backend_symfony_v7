<?php

namespace App\Controller\Admin;

use App\Entity\AppProfile;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Raccourci du menu « Apparence de l'app mobile » (31/10/2026) : ouvre la fiche du profil `mobile`
 * (panneau « Apparence de l'application »), ou le formulaire de création si le site n'en a pas encore.
 */
#[IsGranted('ROLE_ADMIN')]
class AppThemeShortcutController extends AbstractController
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/mobile-theme', name: 'admin_mobile_theme', methods: ['GET'])]
    public function open(): RedirectResponse
    {
        $profile = $this->emProvider->getEntityManager()->getRepository(AppProfile::class)->findOneByApp('mobile');
        $url = $this->urls->unsetAll()->setController(AppProfileCrudController::class);
        $url = $profile !== null ? $url->setAction(Action::EDIT)->setEntityId($profile->getId()) : $url->setAction(Action::NEW);

        return $this->redirect($url->generateUrl());
    }
}
