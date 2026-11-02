<?php

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Codes stables des 401 de Lexik sur les routes protégées (02/11/2026) : la réponse historique `{code: 401, message}` garde `code`
 * en entier (des clients testent sa présence) ; une clé `error` (chaîne) est ajoutée : JWT_NOT_FOUND, JWT_EXPIRED, JWT_INVALID,
 * AUTHENTICATION_FAILED. Règle pour les applications : lire `code` s'il est une chaîne MAJUSCULES_SOULIGNÉES, sinon `error`.
 */
class JwtFailureCodeListener
{
    public function onJwtNotFound(JWTNotFoundEvent $event): void
    {
        $this->addCode($event->getResponse(), 'JWT_NOT_FOUND');
    }

    public function onJwtExpired(JWTExpiredEvent $event): void
    {
        $this->addCode($event->getResponse(), 'JWT_EXPIRED');
    }

    public function onJwtInvalid(JWTInvalidEvent $event): void
    {
        $this->addCode($event->getResponse(), 'JWT_INVALID');
    }

    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $this->addCode($event->getResponse(), 'AUTHENTICATION_FAILED');
    }

    private function addCode(mixed $response, string $code): void
    {
        if (!$response instanceof JsonResponse) {
            return;
        }
        $data = json_decode((string) $response->getContent(), true);
        if (!is_array($data) || isset($data['error'])) {
            return;
        }
        $response->setData($data + ['error' => $code]);
    }
}
