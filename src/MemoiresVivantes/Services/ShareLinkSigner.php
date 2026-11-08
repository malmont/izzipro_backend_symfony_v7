<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\Contributor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lien signé d'un chapitre, dans la forme exacte de GET /chapters/{id}/share-link et /contributor-links
 * (ChapterController) : même donnée signée, même clé (APP_SECRET), même page du frontend. Sert aux invitations par
 * e-mail (08/11/2026), dont le lien se recalcule à partir de la date d'expiration enregistrée.
 */
class ShareLinkSigner
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        private readonly FrontendUrlResolver $frontendUrl
    ) {
    }

    public function signature(string $chapterId, ?string $contributorId, int $expires): string
    {
        $data = $contributorId === null
            ? "chapterId={$chapterId}&expires={$expires}"
            : "chapterId={$chapterId}&contributorId={$contributorId}&expires={$expires}";

        return hash_hmac('sha256', $data, $this->secret);
    }

    /** Page partagée du frontend : lien du chapitre, ou lien personnel du contributeur ; sans requête, domaine du site */
    public function url(Chapter $chapter, ?Contributor $contributor, int $expires, ?Request $request = null): string
    {
        $chapterId = (string) $chapter->getId();
        $contributorId = $contributor === null ? null : (string) $contributor->getId();
        $query = array_filter([
            'contributorId' => $contributorId,
            'expires' => $expires,
            'signature' => $this->signature($chapterId, $contributorId, $expires),
            'chapterId' => $chapterId,
        ], fn ($value) => $value !== null);

        return sprintf('%s/memoires/shared/books/%s/chapters/%s?%s', $this->frontendUrl->baseUrl($request), (string) $chapter->getBook()?->getId(), $chapterId, http_build_query($query));
    }
}
