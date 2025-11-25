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
use App\Services\EmailConfigurationService\EmailConfigurationService;

class RegistrationController extends AbstractController
{
    private LoggerInterface $logger;
    private EmailVerifier $emailVerifier;
    private TenantEntityManagerProvider $tenantEmProvider;
    private HttpClientInterface $client;
    private TenantConnectionManager $tenantManager;
    private GemsuiteClientManager $gemsuiteClientManager;
    private EmailConfigurationService $emailConfigurationService;


    public function __construct(
        EmailVerifier $emailVerifier,
        TenantEntityManagerProvider $tenantEmProvider,
        HttpClientInterface $client,
        TenantConnectionManager $tenantManager,
        LoggerInterface $logger,
        GemsuiteClientManager $gemsuiteClientManager,
        EmailConfigurationService $emailConfigurationService
    ) {
        $this->emailVerifier = $emailVerifier;
        $this->tenantEmProvider = $tenantEmProvider;
        $this->client = $client;
        $this->tenantManager = $tenantManager;
        $this->gemsuiteClientManager = $gemsuiteClientManager;
        $this->logger = $logger;
        $this->emailConfigurationService = $emailConfigurationService;
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

            $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user,
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
        MailerInterface $mailer,
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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email format'], Response::HTTP_BAD_REQUEST);
        }

        [$local, $domain] = explode('@', $email, 2);
        if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
            return $this->json(['error' => 'Email domain appears invalid'], Response::HTTP_BAD_REQUEST);
        }

        $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            return $this->json(['error' => 'User already exists'], Response::HTTP_CONFLICT);
        }

        // 2. Création du Client Gemsuite & User
        $tenantCode = $this->tenantManager->getCurrentTenantCode();
        $gemsuiteClient = $this->gemsuiteClientManager->findOrCreateClient($email, $firstName, $lastName, $tenantCode);

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

        $this->logger->info("[Register API] User créé avec ID: " . $user->getId());

        // 3. Gestion de l'Email (AVEC DEBUG LOGS) 🕵️‍♂️
        
        $this->logger->info("[Register API] Recherche Config Email pour locale: $locale");

        $emailConfig = $this->emailConfigurationService->findOneByLocale($locale);
        $emailConfigTranslation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

        // Diagnostic précis si la config manque
        if (!$emailConfig) {
            $this->logger->error("[Register API] ERREUR: Aucune entité 'EmailConfiguration' trouvée en BDD !");
        } elseif (!$emailConfigTranslation) {
            $this->logger->error("[Register API] ERREUR: Config trouvée mais pas de traduction pour la locale '$locale'.");
        }

        // Si la config existe, on tente l'envoi
        if ($emailConfig && $emailConfigTranslation) {
            
            try {
                $this->logger->info("[Register API] Config OK. Préparation de l'email via : " . $emailConfig->getFromEmail());

                $verificationUrl = $urlGenerator->generate(
                    'app_verify_email',
                    ['token' => $verificationToken],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $fromEmail = $emailConfig->getFromEmail();
                $fromName  = $emailConfigTranslation->getFromName();
                $signature = $emailConfigTranslation->getSignature();
                $logoUrl   = $emailConfig->getLogo();
                
                $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';

                $emailContent = $this->renderView('verification/validation_email.html.twig', [
                    'user'            => $user,
                    'fromName'        => $fromName,
                    'signature'       => $signature,
                    'logoUrl'         => $logoUrl,
                    'domain'          => $domain,
                    'verificationUrl' => $verificationUrl,
                ]);

                $emailMessage = (new Email())
                    ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                    ->to($user->getEmail())
                    ->subject('Veuillez valider votre adresse email')
                    ->html($emailContent);

                $mailer->send($emailMessage);
                
                $this->logger->info("[Register API] SUCCÈS: Email remis au transporteur (ou file d'attente).");

                return $this->json([
                    'message' => 'Registered Successfully. Please check your email to verify your account.'
                ], Response::HTTP_CREATED);

            } catch (\Throwable $e) {
                // En cas d'erreur SMTP, on loggue mais on ne fait pas planter l'inscription
                $this->logger->critical("[Register API] EXCEPTION MAILER : " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                
                // On peut décider de retourner le succès quand même, ou une erreur. 
                // Ici je garde la logique "Succès" pour ne pas bloquer le user, mais l'admin verra les logs.
            }

        } else {
            // Config manquante : On loggue le SKIP
            $this->logger->warning("[Register API] SKIP EMAIL: Passage dans le else (pas de config email valide). L'utilisateur est inscrit mais non notifié.");
            
            $user->setIsVerified(false);
            $user->setVerificationToken(null);
            $em->flush();
        }

        return $this->json([
            'message' => 'Registered Successfully.'
        ], Response::HTTP_CREATED);
    }

    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(Request $request): Response
    {
        $em = $this->tenantEmProvider->getEntityManager();
        $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';
        $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);
        if (!$emailConfig) {
            $emailConfig = null;
        }

        $token = $request->query->get('token');
        if (!$token) {
            return $this->render('verification/error.html.twig', [
                'message' => 'Token manquant.',
                'emailConfig' => $emailConfig,
                'domain' => $domain
            ]);
        }

        $user = $em->getRepository(User::class)->findOneBy(['verificationToken' => $token]);

        if (!$user) {
            return $this->render('verification/error.html.twig', [
                'message' => 'Token invalide ou expiré.',
                'emailConfig' => $emailConfig,
                'domain' => $domain
            ]);
        }

        $user->setIsVerified(true);
        $user->setVerificationToken(null);
        $em->flush();

        return $this->render('verification/success.html.twig', [
            'user' => $user,
            'emailConfig' => $emailConfig,
            'domain' => $domain
        ]);
    }
}
