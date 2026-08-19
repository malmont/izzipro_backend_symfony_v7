<?php

namespace App\ESG\UseCase\Auth;

use App\ESG\DTO\Input\RegisterInputDTO;
use App\ESG\DTO\Output\AuthOutputDTO;
use App\ESG\Entity\EsgCompany;
use App\ESG\Entity\EsgUser;
use App\ESG\Enum\SectorEnum;
use App\ESG\Enum\SizeEnum;
use App\ESG\Enum\TerritoryEnum;
use App\Services\TenantEntityManagerProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RegisterUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtTokenManager
    ) {
    }

    public function execute(RegisterInputDTO $dto): AuthOutputDTO
    {
        $em = $this->emProvider->getEntityManager();

        // 1. Check if email already exists
        $existingUser = $em->getRepository(EsgUser::class)->findOneBy(['email' => $dto->email]);
        if ($existingUser) {
            throw new ConflictHttpException('Un utilisateur avec cette adresse email existe déjà.');
        }

        // 2. Create Company
        $company = new EsgCompany();
        $company->setName($dto->companyName);
        $company->setSector(SectorEnum::from($dto->sector));
        $company->setSizeCategory(SizeEnum::from($dto->sizeCategory));
        $company->setTerritory(TerritoryEnum::from($dto->territory));
        $company->setContactEmail($dto->contactEmail);
        $company->setIsActive(true);

        $em->persist($company);

        // 3. Create User
        $user = new EsgUser();
        $user->setEmail($dto->email);
        $user->setFirstName($dto->firstName);
        $user->setLastName($dto->lastName);
        $user->setRoles(['ROLE_COMPANY']);
        $user->setCompany($company);
        $user->setIsActive(true);
        $user->setIsVerified(false);

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $dto->password);
        $user->setPassword($hashedPassword);

        $em->persist($user);
        $em->flush();

        // 4. Generate JWT
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
