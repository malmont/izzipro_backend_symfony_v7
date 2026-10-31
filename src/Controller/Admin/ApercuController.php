<?php

namespace App\Controller\Admin;

use App\Services\AppProfileService\AppThemePreviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gabarit d'aperçu de l'apparence d'une application (B3, 31/10/2026), servi tel quel (fichier HTML autonome, pas un
 * Twig) aux mêmes rôles que le panneau « Apparence » ; affiché dans un cadre de même origine par le formulaire du profil.
 */
#[IsGranted('ROLE_ADMIN')]
class ApercuController extends AbstractController
{
    public function __construct(private readonly AppThemePreviewService $previews)
    {
    }

    #[Route('/admin/apercu/{code}', name: 'admin_apercu', methods: ['GET'], requirements: ['code' => '[a-z]+'])]
    public function show(string $code): Response
    {
        $path = $this->previews->templatePath($code);
        if ($path === null) {
            throw $this->createNotFoundException(sprintf('Aucun gabarit d\'aperçu pour l\'application « %s »', $code));
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            // Cadre de même origine seulement (le serveur nginx pose déjà SAMEORIGIN ; répété ici pour les tests et tout autre serveur)
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }
}
