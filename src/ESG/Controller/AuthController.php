<?php

namespace App\ESG\Controller;

use App\ESG\DTO\Input\LoginInputDTO;
use App\ESG\DTO\Input\RegisterInputDTO;
use App\ESG\UseCase\Auth\LoginUseCase;
use App\ESG\UseCase\Auth\RegisterUseCase;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/boussole/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly TenantConnectionProvider $tenantConnProvider
    ) {
    }

    #[Route('/register', name: 'esg_auth_register', methods: ['POST'])]
    public function register(
        Request $request,
        RegisterUseCase $useCase,
        ValidatorInterface $validator
    ): Response {
        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new RegisterInputDTO();
        $dto->email = $data['email'] ?? '';
        $dto->password = $data['password'] ?? '';
        $dto->firstName = $data['firstName'] ?? '';
        $dto->lastName = $data['lastName'] ?? '';
        $dto->companyName = $data['companyName'] ?? '';
        $dto->sector = $data['sector'] ?? '';
        $dto->sizeCategory = $data['sizeCategory'] ?? '';
        $dto->territory = $data['territory'] ?? '';
        $dto->contactEmail = $data['contactEmail'] ?? '';

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        try {
            $output = $useCase->execute($dto);
            $response = $this->json($output, Response::HTTP_CREATED);
            $this->setAuthCookie($response, $request, $output->token);
            return $response;
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/login', name: 'esg_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        LoginUseCase $useCase,
        ValidatorInterface $validator
    ): Response {
        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new LoginInputDTO();
        $dto->email = $data['email'] ?? '';
        $dto->password = $data['password'] ?? '';

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        try {
            $output = $useCase->execute($dto);
            $response = $this->json($output, Response::HTTP_OK);
            $this->setAuthCookie($response, $request, $output->token);
            return $response;
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }
    }

    private function setAuthCookie(Response $response, Request $request, string $token): void
    {
        $host = $request->getHost();
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        $cookieDomain = ($host === 'localhost' || str_ends_with($host, '.localhost')) ? null : $host;
        $tenantCode = $this->tenantConnProvider->getTenantCode() ?? 'default';
        $jwtName = 'auth_token_' . $tenantCode;

        $isSecure = true; 
        $sameSite = Cookie::SAMESITE_NONE;

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            $isSecure = false;
            $sameSite = Cookie::SAMESITE_LAX;
        }

        $response->headers->setCookie(
            Cookie::create($jwtName)
                ->withValue($token)
                ->withHttpOnly(true)
                ->withSecure($isSecure)
                ->withSameSite($sameSite) 
                ->withExpires(time() + 3600)
                ->withDomain($cookieDomain)
                ->withPath('/')
        );

        // Add XSRF-TOKEN cookie as well
        $csrfTokenValue = bin2hex(random_bytes(32));
        $csrfCookieName = 'XSRF-TOKEN_' . $tenantCode;
        $response->headers->setCookie(
            Cookie::create($csrfCookieName)
                ->withValue($csrfTokenValue)
                ->withHttpOnly(false)
                ->withSecure($isSecure)
                ->withSameSite($sameSite)
                ->withExpires(time() + 3600)
                ->withDomain($cookieDomain)
                ->withPath('/')
        );
    }
}

