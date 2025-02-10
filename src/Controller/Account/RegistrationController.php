<?php

namespace App\Controller\Account;

use Doctrine\Persistence\ManagerRegistry;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use DateTime;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Entity\EmailConfiguration;

class RegistrationController extends AbstractController
{
    private EmailVerifier $emailVerifier;

    public function __construct(EmailVerifier $emailVerifier)
    {
        $this->emailVerifier = $emailVerifier;
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // encode the plain password
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );
            
            $entityManager->persist($user);
            $entityManager->flush();

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
        ManagerRegistry $doctrine,
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        JWTTokenManagerInterface $jwtManager,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $em = $doctrine->getManager();
        $decoded = json_decode($request->getContent(), true);

        if (!isset($decoded['email'], $decoded['password'], $decoded['firstName'], $decoded['lastName'])) {
            return $this->json(['message' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        $email = $decoded['email'];
        $password = $decoded['password'];
        $firstName = $decoded['firstName'];
        $lastName = $decoded['lastName'];
        $username = $email;

        $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            return $this->json(['message' => 'User already exists'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstname($firstName);
        $user->setLastname($lastName);
        $user->setUsername($username);

        // Déterminer la plateforme et affecter le rôle correspondant.
        // 'pos' pour le point de vente, sinon on affecte ROLE_USER_INTERNET (web et mobile)
        $platform = $decoded['platform'] ?? 'mobile';
        if ($platform === 'pos') {
            $user->setRoles(['ROLE_USER_POS']);
        } else {
            $user->setRoles(['ROLE_USER_INTERNET']);
        }

        $hashedPassword = $passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Marquer l'utilisateur comme non vérifié et générer un token de vérification
        $user->setIsVerified(false);
        $verificationToken = bin2hex(random_bytes(32));
        $user->setVerificationToken($verificationToken);

        $em->persist($user);
        $em->flush();

        // Générer l'URL de vérification (assurez-vous d'avoir un endpoint nommé "app_verify_email" qui traitera la validation)
        $verificationUrl = $urlGenerator->generate(
            'app_verify_email',
            ['token' => $verificationToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $emailConfig = $entityManager->getRepository(EmailConfiguration::class)->findOneBy([]);

        // Si aucune configuration n'est définie, vous pouvez prévoir une valeur par défaut
        if (!$emailConfig) {
            // Valeurs par défaut
            $fromEmail = 'no-reply@votredomaine.com';
            $fromName = 'Votre Société';
        } else {
            $fromEmail = $emailConfig->getFromEmail();
            $fromName = $emailConfig->getFromName();
        }
        
        $emailMessage = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($user->getEmail())
            ->subject('Veuillez valider votre adresse email')
            ->html("<p>Bonjour {$user->getFirstname()},</p>
                    <p>Merci de vous être inscrit. Pour activer votre compte, cliquez sur le lien suivant :</p>
                    <p><a href='{$verificationUrl}'>Valider mon compte</a></p>
                    <p>Si vous n'avez pas demandé cette inscription, ignorez cet email.</p>
                    <p>{$emailConfig?->getSignature()}</p>");
        

        $mailer->send($emailMessage);

        // $jwt = $jwtManager->create($user);

        // // Pour le cas 'web', créer également un refresh token
        // if ($platform === 'web') {
        //     $refreshToken = new RefreshToken();
        //     $refreshToken->setRefreshToken(base64_encode(random_bytes(64)));
        //     $refreshToken->setUsername($user->getUserIdentifier());
        //     $refreshToken->setValid((new \DateTime())->modify('+7 days'));

        //     $entityManager->persist($refreshToken);
        //     $entityManager->flush();

        //     return $this->json([
        //         'message' => 'Registered Successfully. Please check your email to verify your account.',
        //         'token' => $jwt,
        //         'refresh_token' => $refreshToken->getRefreshToken(),
        //     ], Response::HTTP_CREATED);
        // }

        return $this->json([
            'message' => 'Registered Successfully. Please check your email to verify your account.'
        ], Response::HTTP_CREATED);
    }
    
    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET'])]
        public function verifyEmail(Request $request, ManagerRegistry $doctrine): Response
        {
            $em = $doctrine->getManager();
            $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';
            // Récupérer la configuration email depuis l'entité EmailConfiguration
            $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);
            // Vous pouvez définir des valeurs par défaut si aucune configuration n'est trouvée
            if (!$emailConfig) {
                // Créez un objet ou un tableau avec des valeurs par défaut
                $emailConfig = null;
            }

            // Récupérer le token passé en query parameter
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

            // Vérifier l'utilisateur : marquer le compte comme vérifié et vider le token
            $user->setIsVerified(true);
            $user->setVerificationToken(null);
            $em->flush();

            
          
            // Puis, lors du rendu :
            return $this->render('verification/success.html.twig', [
                'user' => $user,
                'emailConfig' => $emailConfig,
                'domain' => $domain
            ]);
        }


}
