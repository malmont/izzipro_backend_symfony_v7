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

class RegistrationController extends AbstractController
{
    private LoggerInterface $logger;
    private EmailVerifier $emailVerifier;
    private TenantEntityManagerProvider $tenantEmProvider;
    private HttpClientInterface $client;
    private TenantConnectionManager $tenantManager;
    private GemsuiteClientManager $gemsuiteClientManager;


    public function __construct(
        EmailVerifier $emailVerifier,
        TenantEntityManagerProvider $tenantEmProvider,
        HttpClientInterface $client,
        TenantConnectionManager $tenantManager,
        LoggerInterface $logger,
        GemsuiteClientManager $gemsuiteClientManager
    ) {
        $this->emailVerifier = $emailVerifier;
        $this->tenantEmProvider = $tenantEmProvider;
        $this->client = $client;
        $this->tenantManager = $tenantManager;
        $this->gemsuiteClientManager = $gemsuiteClientManager;
        $this->logger = $logger;
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
        $decoded = json_decode($request->getContent(), true);

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

        $tenantCode = $this->tenantManager->getCurrentTenantCode();
        $foundClient = $this->gemsuiteClientManager->findOrCreateClient($email, $firstName, $lastName, $tenantCode);

        $user = new User();
        $user->setEmail($email);
        $user->setFirstname($firstName);
        $user->setLastname($lastName);
        $user->setUsername($username);
        $user->setPassword($passwordHasher->hashPassword($user, $password));
        
        if ($foundClient) {
            $user->setGemsuiteClientId($foundClient['id']);
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

        $verificationUrl = $urlGenerator->generate(
            'app_verify_email',
            ['token' => $verificationToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);

        if (!$emailConfig) {
            $fromEmail = 'no-reply@votredomaine.com';
            $fromName  = 'Votre Société';
        } else {
            $fromEmail = $emailConfig->getFromEmail();
            $fromName  = $emailConfig->getFromName();
        }

        $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';

        $emailContent = $this->renderView('verification/validation_email.html.twig', [
            'user'        => $user,
            'emailConfig' => $emailConfig,
            'domain'      => $domain,
            'verificationUrl' => $verificationUrl,
        ]);

        $emailMessage = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($user->getEmail())
            ->subject('Veuillez valider votre adresse email')
            ->html($emailContent);

        $mailer->send($emailMessage);

        return $this->json([
            'message' => 'Registered Successfully. Please check your email to verify your account.'
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
