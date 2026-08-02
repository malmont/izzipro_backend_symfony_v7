<?php

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\Adress;
use App\Entity\EmailConfiguration;
use App\Form\RegistrationFormType;
use App\Security\EmailVerifier;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use App\Dto\TenantConfig;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\EmailConfigurationService\EmailLogoHelper;

class RegistrationController extends AbstractController
{
    private LoggerInterface $logger;
    private EmailVerifier $emailVerifier;
    private TenantEntityManagerProvider $tenantEmProvider;
    private HttpClientInterface $client;
    private TenantConnectionManager $tenantManager;
    private GemsuiteClientManager $gemsuiteClientManager;
    private EmailSenderService $emailSenderService;
    private EmailLogoHelper $emailLogoHelper;


    public function __construct(
        EmailVerifier $emailVerifier,
        TenantEntityManagerProvider $tenantEmProvider,
        HttpClientInterface $client,
        TenantConnectionManager $tenantManager,
        LoggerInterface $logger,
        GemsuiteClientManager $gemsuiteClientManager,
        EmailSenderService $emailSenderService,
        EmailLogoHelper $emailLogoHelper
    ) {
        $this->emailVerifier = $emailVerifier;
        $this->tenantEmProvider = $tenantEmProvider;
        $this->client = $client;
        $this->tenantManager = $tenantManager;
        $this->gemsuiteClientManager = $gemsuiteClientManager;
        $this->logger = $logger;
        $this->emailSenderService = $emailSenderService;
        $this->emailLogoHelper = $emailLogoHelper;
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        $em = $this->tenantEmProvider->getEntityManager();

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $em->persist($user);
            $em->flush();

            $this->emailVerifier->sendEmailConfirmation(
                'app_verify_email',
                $user,
                (new TemplatedEmail())
                    ->from(new Address('michel.almont@gmail.com', '\"Ecommerce Contact\"'))
                    ->to($user->getEmail())
                    ->subject('Please Confirm your Email')
                    ->htmlTemplate('registration/confirmation_email.html.twig')
            );

            return $this->redirectToRoute('app_home');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }


    #[Route('api/register', name: 'register', methods: ['POST'])]
    public function registerApi(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $em = $this->tenantEmProvider->getEntityManager();
        $locale = $request->query->get('locale', 'fr');
        $decoded = json_decode($request->getContent(), true);

        // 1. Validation des données d'entrée
        if (!isset($decoded['email'], $decoded['password'], $decoded['firstName'], $decoded['lastName'])) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        $email = $decoded['email'];
        $password = $decoded['password'];
        $firstName = $decoded['firstName'];
        $lastName = $decoded['lastName'];
        $username = $email;

        // Validation Email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email format'], Response::HTTP_BAD_REQUEST);
        }

        // Vérification DNS simple
        [$local, $domain] = explode('@', $email, 2);
        if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
            return $this->json(['error' => 'Email domain appears invalid'], Response::HTTP_BAD_REQUEST);
        }

        // Vérification doublon
        $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            return $this->json(['error' => 'User already exists'], Response::HTTP_CONFLICT);
        }

        // 2. Détermination du "Tenant Host" (Domaine du client)
        // C'est l'information CRUCIALE pour retrouver la bonne BDD plus tard
        $tenantHost = $request->headers->get('x-tenant-host');

        // Fallback : Si le header est manquant, on tente de le deviner via l'Origin (ex: https://karaandb.com)
        if (!$tenantHost) {
            $origin = $request->headers->get('origin');
            if ($origin) {
                $tenantHost = parse_url($origin, PHP_URL_HOST);
            }
        }

        // 3. Création du User (Mode Autonome - GemSuite déconnecté)
        $gemsuiteClient = null;

        // On récupère l'EntityManager ICI
        $em = $this->tenantEmProvider->getEntityManager();

        $user = new User();
        $user->setEmail($email);
        $user->setFirstname($firstName);
        $user->setLastname($lastName);
        $user->setUsername($username);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        if ($gemsuiteClient) {
            $user->setGemsuiteClient($gemsuiteClient);
        }

        $platform = $decoded['platform'] ?? 'mobile';
        if ($platform === 'pos') {
            $user->setRoles(['ROLE_USER_POS']);
        } else {
            $user->setRoles(['ROLE_USER_INTERNET']);
        }

        $user->setIsVerified(false);
        $verificationToken = bin2hex(random_bytes(32));
        $user->setVerificationToken($verificationToken);

        $em->persist($user);
        $em->flush();

        $this->logger->info("[Register API] User créé ID: " . $user->getId() . " pour le domaine: " . ($tenantHost ?? 'Inconnu'));

        // 4. Gestion de l'Email
        try {
            // --- CONSTRUCTION DE L'URL INTELLIGENTE ---
            // On génère un lien vers le Backend, MAIS on y ajoute l'info du domaine client (?tenant_host=...)
            // Ex: https://gem-portal-backend.com/verify/email?token=XYZ&tenant_host=karaandb.com

            $routeParams = ['token' => $verificationToken];
            if ($tenantHost) {
                $routeParams['tenant_host'] = $tenantHost;
            }

            $verificationUrl = $urlGenerator->generate(
                'app_verify_email',
                $routeParams,
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Pour les assets (logos), on utilise le domaine actuel de l'API pour éviter les problèmes SSL/CORS
            $baseUrl = $request->getSchemeAndHttpHost();

            $this->emailSenderService->sendTemplatedEmail(
                $user->getEmail(),
                'Veuillez valider votre adresse email',
                'verification/validation_email.html.twig',
                [
                    'user'            => $user,
                    'verificationUrl' => $verificationUrl,
                ],
                $locale,
                $baseUrl
            );
        } catch (\Throwable $e) {
            $this->logger->critical("[Register API] ERREUR EMAIL : " . $e->getMessage());
        }

        return $this->json([
            'message' => 'Registered Successfully.'
        ], Response::HTTP_CREATED);
    }

    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(Request $request): Response
    {
        // 1. Bascule Tenant
        $targetHost = $request->query->get('tenant_host');
        if ($targetHost) {
            try {
                $tenantConfig = $this->tenantManager->findTenantConfigByHost($targetHost);
                if ($tenantConfig) {
                    $this->tenantManager->switchToTenant($tenantConfig);
                    $this->logger->info("[Verify Email] Bascule réussie sur : " . $tenantConfig->getDbname());
                } else {
                    $this->logger->warning("[Verify Email] Tenant introuvable pour le host : " . $targetHost);
                }
            } catch (\Exception $e) {
                $this->logger->error("[Verify Email] Erreur bascule : " . $e->getMessage());
                // On continue pour tenter d'afficher l'erreur proprement
            }
        }

        $em = $this->tenantEmProvider->getEntityManager();
        $connection = $em->getConnection();

        // 2. Récupération Config Email (Indispensable pour votre Twig)
        $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);
        $baseUrl = $request->getSchemeAndHttpHost();

        // Pré-calcul du logo pour le passer proprement
        $logoUrl = $this->emailLogoHelper->getLogoUrl($emailConfig, $baseUrl);

        $token = $request->query->get('token');

        // 3. Cas : Token manquant
        if (!$token) {
            return $this->render('verification/error.html.twig', [
                'message'     => 'Token manquant.',
                'logoUrl'     => $logoUrl,
                'domain'      => $baseUrl,
                'emailConfig' => $emailConfig // <--- AJOUTÉ ICI
            ]);
        }

        // 4. Recherche User via SQL Brut (Contournement bug Doctrine table "user")
        $result = null;
        try {
            $sql = 'SELECT id FROM "user" WHERE verification_token = :token LIMIT 1';
            $result = $connection->fetchAssociative($sql, ['token' => $token]);
        } catch (\Exception $e) {
            $this->logger->error("[Verify Email] Erreur SQL : " . $e->getMessage());
        }

        // 5. Validation si trouvé
        if (is_array($result) && isset($result['id'])) {
            // Chargement entité via ID
            $user = $em->getRepository(User::class)->find($result['id']);

            if ($user) {
                $user->setIsVerified(true);
                $user->setVerificationToken(null);
                $em->flush();

                // Redirection Front Client
                if ($targetHost) {
                    return $this->redirect('https://' . $targetHost . '/login?verified=true');
                }

                // Succès Backend
                return $this->render('verification/success.html.twig', [
                    'user'        => $user,
                    'logoUrl'     => $logoUrl,
                    'domain'      => $baseUrl,
                    'emailConfig' => $emailConfig
                ]);
            }
        }

        // 6. Cas : Token invalide ou expiré
        return $this->render('verification/error.html.twig', [
            'message'     => 'Ce lien de validation est invalide ou a expiré.',
            'logoUrl'     => $logoUrl,
            'domain'      => $baseUrl,
            'emailConfig' => $emailConfig
        ]);
    }
    #[Route('/api/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function resendVerificationEmail(
        Request $request,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $em = $this->tenantEmProvider->getEntityManager();
        $decoded = json_decode($request->getContent(), true);
        $email = $decoded['email'] ?? null;
        $locale = $request->query->get('locale', 'fr');

        if (!$email) {
            return $this->json(['error' => 'Email manquant'], Response::HTTP_BAD_REQUEST);
        }

        // 1. Recherche de l'utilisateur
        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {

            return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($user->isVerified()) {
            return $this->json(['message' => 'Ce compte est déjà vérifié.'], Response::HTTP_BAD_REQUEST);
        }

        $verificationToken = bin2hex(random_bytes(32));
        $user->setVerificationToken($verificationToken);
        $em->flush();

        $tenantHost = $request->headers->get('x-tenant-host');
        if (!$tenantHost) {
            $origin = $request->headers->get('origin');
            if ($origin) {
                $tenantHost = parse_url($origin, PHP_URL_HOST);
            }
        }

        // 4. Envoi de l'email
        try {
            // Construction URL Bilingue
            $routeParams = ['token' => $verificationToken];
            if ($tenantHost) {
                $routeParams['tenant_host'] = $tenantHost;
            }

            $verificationUrl = $urlGenerator->generate(
                'app_verify_email',
                $routeParams,
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // URL Logo Absolue
            $baseUrl = $request->getSchemeAndHttpHost();

            $this->emailSenderService->sendTemplatedEmail(
                $user->getEmail(),
                'Nouveau lien de validation de compte',
                'verification/validation_email.html.twig',
                [
                    'user'            => $user,
                    'verificationUrl' => $verificationUrl,
                ],
                $locale,
                $baseUrl
            );

            return $this->json(['message' => 'Email de vérification renvoyé avec succès.'], Response::HTTP_OK);
        } catch (\Throwable $e) {
            $this->logger->critical("[Resend Verif] Erreur envoi : " . $e->getMessage());
            return $this->json(['error' => 'Erreur lors de l\'envoi de l\'email.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
