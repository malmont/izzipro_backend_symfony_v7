<?php

namespace App\Security\Entry;

use App\Entity\User;
use App\Security\OtpSession;
use App\Services\Entry\AdminPassService;
use App\Services\Entry\EntryException;
use App\Services\Entry\EntryService;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Twig\Environment;

/**
 * `POST /admin/entree?tenant=<site visé>` (lot AG-3c, 17/11/2026) : ouvre la session d'EasyAdmin d'une entrée, sur présentation d'un
 * laissez-passer (`AdminPassService` : signé, 60 s, pour ce site, usage unique ; champ `pass` du corps, jamais dans l'adresse).
 * - Mode `agency` : la session porte l'OPÉRATEUR EXTÉRIEUR (aucun compte chez le client) ; l'entrée est relue à chaque requête.
 * - Mode `super_admin` : la session porte LE compte de la plateforme du site visé, sans reconnexion ni code : la validation du code
 *   faite sur le site d'attache (moins de 12 h, heure d'origine gardée) vaut ici.
 * Refusé : page 403, rien n'est ouvert. Le laissez-passer est consumé seulement quand tout le reste est vérifié.
 */
final class AdminPassAuthenticator extends AbstractAuthenticator
{
    public const PATH = '/admin/entree';
    public const OTP_MAX_AGE = 43200;
    private const CLAIMS = '_admin_pass_claims';

    public function __construct(
        private readonly AdminPassService $passes,
        private readonly EntryService $entries,
        private readonly TenantConnectionProvider $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly Environment $twig,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && \App\Services\Http\RequestPath::of($request) === self::PATH;
    }

    public function authenticate(Request $request): Passport
    {
        $current = $this->tenants->getTenantCode();
        try {
            $claims = $this->passes->consume($request->request->get('pass'), $current);
        } catch (EntryException $e) {
            throw new CustomUserMessageAuthenticationException((string) ($e->reason ?? $e->errorCode));
        }
        if (time() - $claims['otpAt'] > self::OTP_MAX_AGE || $claims['otpAt'] > time()) {
            throw new CustomUserMessageAuthenticationException('otp');
        }
        $entry = ['id' => $claims['entry'], 'mode' => $claims['mode'], 'home' => $claims['home'], 'target' => $claims['target'],
            'account' => $claims['account'] ?? null, 'email' => $claims['email'] ?? null];
        if ($claims['mode'] === EntryService::MODE_AGENCY) {
            try {
                $user = $this->entries->userFor(['entry' => ['id' => $claims['entry'], 'mode' => EntryService::MODE_AGENCY, 'home' => $claims['home']],
                    'username' => 'entry:' . $claims['entry'], 'tenant_code' => $current], $current);
            } catch (\Throwable) {
                throw new CustomUserMessageAuthenticationException('entry');
            }
        } elseif ($claims['mode'] === EntryService::MODE_SUPER_ADMIN) {
            $user = $this->emProvider->getEntityManager()->find(User::class, (int) ($claims['account'] ?? 0));
            if (!$user instanceof User || !PlatformAccessJournal::isPlatformAccount($user->getRoles()) || $user->getEmail() !== ($claims['email'] ?? null)
                || $this->entries->sessionEntry($entry, $current) !== null) {
                throw new CustomUserMessageAuthenticationException('entry');
            }
        } else {
            throw new CustomUserMessageAuthenticationException('pass_malformed');
        }
        $request->attributes->set(self::CLAIMS, $entry + ['otpAt' => $claims['otpAt']]);

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $claims = (array) $request->attributes->get(self::CLAIMS);
        $session = $request->getSession();
        $row = $claims['id'] ?? null;
        AdminEntrySession::put($session, ['id' => (int) $row, 'mode' => (string) $claims['mode'], 'home' => (string) $claims['home'], 'target' => (string) $claims['target'],
            'account' => $claims['account'] ?? null, 'email' => $claims['email'] ?? null,
            'expiresAt' => (new \DateTimeImmutable('+' . EntryService::ADMIN_TTL . ' seconds', new \DateTimeZone('UTC')))->format(\DATE_ATOM)]);
        $user = $token->getUser();
        if ($user instanceof User) {
            // le compte de la plateforme : le code validé sur le site d'attache vaut ici, à son heure d'origine (jamais rajeunie)
            OtpSession::markValidatedAt($session, $user, $this->tenants->getTenantCode(), (int) $claims['otpAt']);
        }

        return new RedirectResponse('/admin');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response($this->twig->render('admin/entree/refus.html.twig', ['reason' => $exception->getMessageKey()]), 403);
    }
}
