<?php

namespace App\Controller\Account;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Entity\EmailConfiguration;

class ResetPasswordController extends AbstractController
{
    /**
     * Demande de réinitialisation : l'utilisateur fournit son email.
     */
    #[Route('/api/password-reset/request', name: 'app_password_reset_request', methods: ['POST'])]
        public function requestPasswordReset(
            Request $request,
            ManagerRegistry $doctrine,
            MailerInterface $mailer,
            UrlGeneratorInterface $urlGenerator
        ): Response {
            $data = json_decode($request->getContent(), true);
            if (!isset($data['email'])) {
                return $this->json(['message' => 'Email is required.'], Response::HTTP_BAD_REQUEST);
            }
            
            $emailInput = $data['email'];
            $em = $doctrine->getManager();
            $user = $em->getRepository(User::class)->findOneBy(['email' => $emailInput]);
            
            // Pour des raisons de sécurité, toujours renvoyer un message générique.
            if (!$user) {
                return $this->json(['message' => 'If your email exists in our system, you will receive a password reset link.']);
            }
            
            // Génération d'un token de réinitialisation et date d'expiration (ex. 1 heure)
            $resetToken = bin2hex(random_bytes(32));
            $user->setResetToken($resetToken);
            $user->setResetTokenExpiresAt(new \DateTime('+1 hour'));
            $em->persist($user);
            $em->flush();
            // Générer l'URL de réinitialisation
            $resetUrl = $urlGenerator->generate(
                'app_password_reset_confirm_form', // endpoint du formulaire de réinitialisation
                ['token' => $resetToken],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
            $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);

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
                ->subject('Réinitialisation de votre mot de passe')
                ->html(
                    "<p>Bonjour {$user->getFirstname()},</p>
                    <p>Vous avez demandé la réinitialisation de votre mot de passe.</p>
                    <p>Cliquez sur le lien suivant pour réinitialiser votre mot de passe :</p>
                    <p><a href=\"{$resetUrl}\">Réinitialiser mon mot de passe</a></p>
                    <p>Ce lien expirera dans 1 heure.</p>"
                );

                    
            $mailer->send($emailMessage);
            
            return $this->json(['message' => 'If your email exists in our system, you will receive a password reset link.']);
        }
    
    /**
     * Confirmation de réinitialisation : l'utilisateur envoie le token et son nouveau mot de passe.
     */
        #[Route('/api/password-reset/confirm', name: 'app_password_reset_confirm', methods: ['POST'])]
        public function confirmPasswordReset(
            Request $request,
            ManagerRegistry $doctrine,
            UserPasswordHasherInterface $passwordHasher
        ): Response {
            $data = json_decode($request->getContent(), true) ?: $request->request->all();
        
            if (!isset($data['token'], $data['newPassword'])) {
                return $this->json(['message' => 'Token and new password are required.'], Response::HTTP_BAD_REQUEST);
            }
            
            $token = $data['token'];
            $newPassword = $data['newPassword'];
            
            $em = $doctrine->getManager();
            $user = $em->getRepository(User::class)->findOneBy(['resetToken' => $token]);
            
            if (!$user) {
                return $this->json(['message' => 'Invalid token.'], Response::HTTP_BAD_REQUEST);
            }
            
            if ($user->getResetTokenExpiresAt() < new \DateTime()) {
                return $this->json(['message' => 'The token has expired.'], Response::HTTP_BAD_REQUEST);
            }
            
            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->setPassword($hashedPassword);
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);
            $em->persist($user);
            $em->flush();
            
            // Rendu d'un template Twig pour afficher le message de succès
            return $this->render('reset_password/success.html.twig', [
                'message' => 'Password reset successfully.',
            ]);
        }
    
    /**
     * (Optionnel) Afficher un formulaire web pour réinitialiser le mot de passe.
     */
    #[Route('/api/password-reset/form', name: 'app_password_reset_confirm_form', methods: ['GET'])]
    public function resetPasswordForm(Request $request): Response
    {
        $token = $request->query->get('token');
        return $this->render('reset_password/form.html.twig', [
            'token' => $token
        ]);
    }
}
