<?php
namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\OtpCode;
use DateTime;
use App\Entity\Entreprise;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Services\TenantEntityManagerProvider;

class OtpController extends AbstractController
{
    private TenantEntityManagerProvider $tenantEmProvider;
    private UrlGeneratorInterface $urlGenerator;
    private $session;

    public function __construct(TenantEntityManagerProvider $tenantEmProvider, RequestStack $requestStack, UrlGeneratorInterface $urlGenerator)
    {
        $this->tenantEmProvider = $tenantEmProvider;
        $this->session = $requestStack->getSession();
        $this->urlGenerator = $urlGenerator;
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
                $em = $this->tenantEmProvider->getEntityManager();
                $user = $em->getRepository(User::class)->find($pendingUserId);
                if (!$user) {
                    return $this->redirectToRoute('app_login');
                }
                
                $otpCode = $em->getRepository(OtpCode::class)->findOneBy([
                    'userOtp' => $user,
                    'code' => $otp,
                ]);
                
                if (!$otpCode || $otpCode->getExpiration() < new DateTime()) {
                    $error = 'Code OTP invalide ou expiré';
                } else {
                    // Le code est correct : on supprime l'OTP et on marque la session comme validée
                    $em->remove($otpCode);
                    $em->flush();
                    $this->session->set('otp_validated', true);
                    $this->session->remove('pending_otp_user');
                    
                    // Rediriger vers le dashboard ou une autre route protégée
                    return new RedirectResponse($this->urlGenerator->generate('app_account'));
                }
            }
        }
        // Récupérer les informations de l'entreprise (supposons une seule entreprise)
        $entreprise = $em
            ->getRepository(Entreprise::class)
            ->findOneBy([]);

        // Construire le domaine pour le logo, par exemple : https://backend-strapi.online/assets/uploads/email-logos/
        $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';
        
        return $this->render('account/otp_verify.html.twig', [
            'error' => $error,
            'csrf_token' => $this->container->get('security.csrf.token_manager')->getToken('otp_verify')->getValue(),
            'entreprise' => $entreprise,
            'domain' => $domain,
        ]);
    }
}
