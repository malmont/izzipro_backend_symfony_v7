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
    private ?\App\Services\MediaUrlResolver $mediaUrlResolver;

    public function __construct(
        TenantEntityManagerProvider $tenantEmProvider, 
        RequestStack $requestStack, 
        UrlGeneratorInterface $urlGenerator,
        ?\App\Services\MediaUrlResolver $mediaUrlResolver = null
    ) {
        $this->tenantEmProvider = $tenantEmProvider;
        $this->session = $requestStack->getSession();
        $this->urlGenerator = $urlGenerator;
        $this->mediaUrlResolver = $mediaUrlResolver;
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
            if (!$this->isCsrfTokenValid('otp_verify', (string) $submittedToken)) {
                $error = 'Jeton CSRF invalide.';
            } else {
                $otp = trim((string) $request->request->get('otp', ''));
                if (strlen($otp) < 4 || strlen($otp) > 10 || !preg_match('/^[0-9A-Za-z]+$/', $otp)) {
                    $error = 'Format de code OTP invalide.';
                } else {
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
                        
                        // Rediriger vers le dashboard admin (/admin)
                        return new RedirectResponse($this->urlGenerator->generate('admin'));
                    }
                }
            }
        }
        // Récupérer les informations de l'entreprise (supposons une seule entreprise)
        $entreprise = $em
            ->getRepository(Entreprise::class)
            ->findOneBy([]);

        // Construire le domaine pour le logo (CDN si configuré ou hôte local)
        $domain = ($this->mediaUrlResolver 
            ? $this->mediaUrlResolver->getEmailLogosBaseUrl($request->getSchemeAndHttpHost()) 
            : $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos') . '/';
        
        return $this->render('account/otp_verify.html.twig', [
            'error' => $error,
            'csrf_token' => $this->container->get('security.csrf.token_manager')->getToken('otp_verify')->getValue(),
            'entreprise' => $entreprise,
            'domain' => $domain,
        ]);
    }
}
