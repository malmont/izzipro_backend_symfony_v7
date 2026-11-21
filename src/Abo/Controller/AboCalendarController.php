<?php

namespace App\Abo\Controller;

use App\Abo\Services\AboCalendarFeedService;
use App\Abo\Services\AboException;
use App\Entity\Subscription;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Liens d'agenda `.ics` (lot ABO-4, 21/11/2026, `docs/boutique.md` § « Liste d'attente, rappels et calendrier »).
 * - Lecture : `GET /api/calendrier/{jeton}.ics?tenant=…`, sans JWT (les agendas n'envoient pas d'en-tête) ; le même 404 pour toute raison ;
 *   débit limité par jeton et par adresse IP (429). Le jeton n'est jamais journalisé (`SensitivePathProcessor`).
 * - Création ou changement du lien (l'ancien ne vaut plus) : l'abonné `POST /api/subscriptions/{id}/calendrier`, l'intervenant
 *   `POST /api/staff/abonnements/calendrier` ; l'adresse n'est rendue qu'à ce moment.
 * - L'intervenant : `GET /api/staff/abonnements/calendrier` dit si un lien existe et rend la phrase à montrer (ses adresses de visite iront
 *   dans son agenda), servie en fr et en en.
 */
class AboCalendarController extends AbstractController
{
    public function __construct(
        private readonly AboCalendarFeedService $feeds,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TranslatorInterface $translator,
        private readonly \App\Services\EmailConfigurationService\EmailLocale $locale,
        #[Autowire(service: 'limiter.calendar_feed_token')] private readonly RateLimiterFactory $byToken,
        #[Autowire(service: 'limiter.calendar_feed_ip')] private readonly RateLimiterFactory $byIp,
    ) {
    }

    #[Route('/api/calendrier/{token}.ics', name: 'api_calendar_feed', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function feed(Request $request, string $token): Response
    {
        if (!$this->byToken->create(hash('sha256', $token))->consume()->isAccepted() || !$this->byIp->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return new Response('', 429, ['Retry-After' => '3600']);
        }
        $ics = $this->feeds->feed($token);
        if ($ics === null) {
            return new Response('', 404, ['Cache-Control' => 'no-store']);
        }

        return new Response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="agenda.ics"',
            'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex']);
    }

    /** L'abonné crée ou change le lien d'agenda de son abonnement → 201 { url, createdAt } */
    #[Route('/api/subscriptions/{id}/calendrier', name: 'api_subscriptions_calendar', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER_INTERNET')]
    public function subscriberLink(Request $request, int $id): JsonResponse
    {
        $subscription = $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find($id);
        $user = $this->getUser();
        if ($subscription === null || !$user instanceof User || $subscription->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'SUBSCRIPTION_NOT_FOUND', 'code' => 'SUBSCRIPTION_NOT_FOUND', 'message' => 'Abonnement introuvable.'], 404);
        }

        return $this->json($this->link($request, AboCalendarFeedService::OWNER_SUBSCRIPTION, $id), 201);
    }

    /** L'intervenant : son lien existe-t-il, et la phrase à lui montrer → 200 { exists, notice } */
    #[Route('/api/staff/abonnements/calendrier', name: 'api_staff_calendar_state', methods: ['GET'])]
    #[IsGranted('ROLE_STAFF_PROVIDER')]
    public function staffState(Request $request): JsonResponse
    {
        return $this->json(['exists' => $this->feeds->exists(AboCalendarFeedService::OWNER_STAFF, $this->staffId()), 'notice' => $this->notice($request)]);
    }

    /** L'intervenant crée ou change son lien d'agenda → 201 { url, createdAt, notice } */
    #[Route('/api/staff/abonnements/calendrier', name: 'api_staff_calendar', methods: ['POST'])]
    #[IsGranted('ROLE_STAFF_PROVIDER')]
    public function staffLink(Request $request): JsonResponse
    {
        return $this->json($this->link($request, AboCalendarFeedService::OWNER_STAFF, $this->staffId()) + ['notice' => $this->notice($request)], 201);
    }

    /** @return array{url: string, createdAt: string} */
    private function link(Request $request, string $ownerType, int $ownerId): array
    {
        $link = $this->feeds->rotate($ownerType, $ownerId, $request->getHttpHost());
        // le site du lien : l'agenda n'envoie ni en-tête ni session ; `?tenant=` le désigne (TenantDoctrineSwitcherListener)
        $site = $request->headers->get('X-Tenant-Host') ?: $request->getHost();
        $link['url'] .= '?tenant=' . rawurlencode((string) $site);

        return $link;
    }

    private function notice(Request $request): string
    {
        $asked = $request->query->get('locale');
        $user = $this->getUser();

        return $this->translator->trans('calendar.staff_notice', [], 'abo', in_array($asked, ['fr', 'en'], true) ? $asked : $this->locale->forUser($user instanceof User ? $user : null));
    }

    private function staffId(): int
    {
        $user = $this->getUser();

        return $user instanceof User ? (int) $user->getId() : throw new AboException(403, 'FORBIDDEN', 'Accès refusé.');
    }
}
