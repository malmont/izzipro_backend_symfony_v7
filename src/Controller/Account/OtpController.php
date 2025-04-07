<?php

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\OtpCode;
use DateTime;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RequestStack;

class OtpController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private $session;

    public function __construct(EntityManagerInterface $entityManager, RequestStack $requestStack)
    {
        $this->entityManager = $entityManager;
        $this->session = $requestStack->getSession();
    }
    
    #[Route('/account/otp', name: 'account_otp')]
    public function verifyOtp(Request $request): Response
    {
        // Utilisation de $this->session ici pour récupérer les informations de session
        $pendingUserId = $this->session->get('pending_otp_user');
        if (!$pendingUserId) {
            return $this->redirectToRoute('app_login');
        }
        
        $error = null;
        if ($request->isMethod('POST')) {
            // Vérification du token CSRF
            $submittedToken = $request->request->get('_csrf_token');
            if (!$this->isCsrfTokenValid('otp_verify', $submittedToken)) {
                $error = 'Jeton CSRF invalide.';
            } else {
                $otp = $request->request->get('otp');
                // Récupérer l'utilisateur et son code OTP
                $user = $this->entityManager->getRepository(User::class)->find($pendingUserId);
                if (!$user) {
                    return $this->redirectToRoute('app_login');
                }
                
                $otpCode = $this->entityManager->getRepository(OtpCode::class)->findOneBy([
                    'userOtp' => $user,
                    'code' => $otp,
                ]);
                
                if (!$otpCode || $otpCode->getExpiration() < new DateTime()) {
                    $error = 'Code OTP invalide ou expiré';
                } else {
                    // Le code est correct : on supprime l'OTP et on marque la session comme validée
                    $this->entityManager->remove($otpCode);
                    $this->entityManager->flush();
                    $this->session->set('otp_validated', true);
                    $this->session->remove('pending_otp_user');
                    
                    // Rediriger vers le dashboard ou une autre route protégée
                    return new RedirectResponse($this->urlGenerator->generate('app_account'));
                }
            }
        }
        
        return $this->render('account/otp_verify.html.twig', [
            'error' => $error,
            'csrf_token' => $this->container->get('security.csrf.token_manager')->getToken('otp_verify')->getValue(),
        ]);
    }
}
