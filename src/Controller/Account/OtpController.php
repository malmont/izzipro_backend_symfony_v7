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
        ?\App\Services\MediaUrlResolver $mediaUrlResolver = null,
        // Lot SÉCURITÉ-2 : la même limite que la vérification du code par l'API (5 essais par 10 minutes et par compte)
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'limiter.otp_verify_account')] private readonly ?\Symfony\Component\RateLimiter\RateLimiterFactory $attempts = null,
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
        $em = $this->tenantEmProvider->getEntityManager();
        if ($request->isMethod('POST')) {
            // Vérification du token CSRF
            $submittedToken = $request->request->get('_csrf_token');
            if (!$this->isCsrfTokenValid('otp_verify', (string) $submittedToken)) {
                $error = 'Jeton CSRF invalide.';
            } else {
                $otp = trim((string) $request->request->get('otp', ''));
                // Lot SÉCURITÉ-2 : un code de 6 chiffres valable 5 minutes se devine sans limite. Chaque essai compte, par compte ;
                // au-delà, refus jusqu'à la fin de la fenêtre, et les codes en attente de ce compte sont effacés (il en redemande un).
                $limit = $this->attempts?->create('easyadmin|' . $pendingUserId)->consume(1);
                if ($limit !== null && !$limit->isAccepted()) {
                    $user = $em->getRepository(User::class)->find($pendingUserId);
                    foreach ($user !== null ? $em->getRepository(OtpCode::class)->findBy(['userOtp' => $user, 'purpose' => OtpCode::PURPOSE_LOGIN]) : [] as $pending) {
                        $em->remove($pending);
                    }
                    $em->flush();
                    $error = 'Trop d\'essais : reconnectez-vous dans quelques minutes pour recevoir un nouveau code.';
                } elseif (strlen($otp) < 4 || strlen($otp) > 10 || !preg_match('/^[0-9A-Za-z]+$/', $otp)) {
                    $error = 'Format de code OTP invalide.';
                } else {
                    // Récupérer l'utilisateur et son code OTP
                    $user = $em->getRepository(User::class)->find($pendingUserId);
                    if (!$user) {
                        return $this->redirectToRoute('app_login');
                    }
                    
                    $otpCode = $em->getRepository(OtpCode::class)->findOneBy([
                        'userOtp' => $user,
                        'code' => $otp,
                        'purpose' => OtpCode::PURPOSE_LOGIN, // codes de connexion seulement (01/11/2026)
                    ]);
                
                    if (!$otpCode || $otpCode->getExpiration() < new DateTime()) {
                        $error = 'Code OTP invalide ou expiré';
                    } else {
                        // Le code est correct : on supprime l'OTP et on marque la session comme validée
                        $em->remove($otpCode);
                        $em->flush();
                        \App\Security\OtpSession::markValidated($this->session, $user); // lot SÉCURITÉ-2 : liée à CE compte
                        $this->session->remove('pending_otp_user');
                        // Lot AG-2 : une nouvelle entrée ; le compte de la plateforme sera tracé à sa première page de chaque site (PlatformAccessSubscriber)
                        $this->session->remove(\App\Security\PlatformAccessSubscriber::SESSION_KEY);
                        
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
