<?php

namespace App\Controller\Admin;

use App\Dto\AppKeyInputDto;
use App\Services\AppKeyService\AppKeyException;
use App\Services\AppKeyService\AppKeyService;
use App\UseCase\AppKeyUseCase\GenerateAppKeyUseCase;
use App\UseCase\AppKeyUseCase\ListAppKeysUseCase;
use App\UseCase\AppKeyUseCase\RevokeAppKeyUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran EasyAdmin « Clés des applications » (administrateur du site) : liste, génération (la clé s'affiche une fois
 * et part par e-mail au contact du site), révocation. Formulaires POST protégés par jeton CSRF. ROLE_ADMIN par
 * access_control (^/admin).
 */
class AppKeyAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_app_keys';

    public function __construct(
        private readonly ListAppKeysUseCase $list,
        private readonly GenerateAppKeyUseCase $generate,
        private readonly RevokeAppKeyUseCase $revoke
    ) {
    }

    #[Route('/admin/app-keys', name: 'admin_app_keys', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('admin/app_keys/index.html.twig', [
            'keys' => $this->list->execute(),
            'apps' => array_keys(AppKeyService::APPS),
            'labels' => AppKeyService::LABELS,
            'csrf_id' => self::CSRF_ID,
            'new_key' => $request->getSession()->getFlashBag()->get('app_key_plain')[0] ?? null,
        ]);
    }

    #[Route('/admin/app-keys/generate', name: 'admin_app_keys_generate', methods: ['POST'])]
    public function generate(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, recommencez.');

            return $this->back($request);
        }
        try {
            $input = AppKeyInputDto::fromArray([
                'app' => $request->request->get('app'), 'label' => $request->request->get('label'),
                'expiresInDays' => $request->request->get('expiresInDays'), 'sendEmail' => $request->request->getBoolean('sendEmail'),
            ]);
            $result = $this->generate->execute($input, $this->getUser()?->getUserIdentifier() ?? 'admin', $request->getSchemeAndHttpHost());
            $request->getSession()->getFlashBag()->add('app_key_plain', $result['key']);
            $this->addFlash('success', $result['emailSentTo'] ? sprintf('Clé créée et envoyée à %s.', $result['emailSentTo']) : 'Clé créée. Notez-la : elle ne sera plus affichée.');
        } catch (AppKeyException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->back($request);
    }

    #[Route('/admin/app-keys/{id}/revoke', name: 'admin_app_keys_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, recommencez.');
        } else {
            try {
                $this->revoke->execute($id);
                $this->addFlash('success', 'Clé révoquée : l\'application ne pourra plus s\'activer avec elle.');
            } catch (AppKeyException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->back($request);
    }

    /** Retour à l'écran, en gardant le paramètre ?tenant= s'il a servi à ouvrir l'administration */
    private function back(Request $request): Response
    {
        $tenant = $request->query->get('tenant');

        return $this->redirectToRoute('admin_app_keys', $tenant ? ['tenant' => $tenant] : []);
    }
}
