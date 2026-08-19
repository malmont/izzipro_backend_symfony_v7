<?php

namespace App\ESG\UseCase\Auth;

use App\ESG\DTO\Input\LoginInputDTO;
use App\ESG\DTO\Output\AuthOutputDTO;
use App\ESG\Entity\EsgUser;
use App\Services\TenantEntityManagerProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

class LoginUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtTokenManager
    ) {
    }

    public function execute(LoginInputDTO $dto): AuthOutputDTO
    {
        $em = $this->emProvider->getEntityManager();

        /** @var EsgUser|null $user */
        $user = $em->getRepository(EsgUser::class)->findOneBy(['email' => $dto->email]);
        if (!$user) {
            throw new NotFoundHttpException('Identifiants incorrects.');
        }

        // Verify password
        if (!$this->passwordHasher->isPasswordValid($user, $dto->password)) {
            throw new BadCredentialsException('Identifiants incorrects.');
        }

        // Check active status
        if (!$user->isActive()) {
            throw new AccessDeniedHttpException('Votre compte utilisateur est désactivé.');
        }

        if ($user->getCompany() && !$user->getCompany()->isActive()) {
            throw new AccessDeniedHttpException('Votre entreprise est désactivée.');
        }

        // Update login timestamp
        $user->setLastLoginAt(new \DateTimeImmutable());
        $em->flush();

        // Generate token
        $token = $this->jwtTokenManager->create($user);

        return new AuthOutputDTO(
            $token,
            $user->getId(),
            $user->getEmail(),
            $user->getFirstName(),
            $user->getLastName()
        );
    }
}
