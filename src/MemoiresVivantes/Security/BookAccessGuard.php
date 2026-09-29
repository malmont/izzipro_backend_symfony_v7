<?php

namespace App\MemoiresVivantes\Security;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Accès à un livre, commun aux contrôleurs du module :
 * - canView : lien de chapitre signé (contributeurs, partage, clé APP_SECRET) ou voter BOOK_VIEW (propriétaire, admin) ;
 * - canManage : utilisateur connecté, propriétaire du livre ou admin (voter BOOK_EDIT) : génération, paiement,
 *   impression, tout ce qui coûte ou engage.
 * Renvoie null si l'accès est accordé, sinon [code HTTP, message].
 */
class BookAccessGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantEntityManagerProvider $emProvider,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret
    ) {
    }

    /** @return array{0: int, 1: string}|null */
    public function canManage(Book $book, string $attribute = BookVoter::EDIT): ?array
    {
        if ($this->security->getUser() === null) {
            return [Response::HTTP_UNAUTHORIZED, 'Authentification requise.'];
        }

        return $this->security->isGranted($attribute, $book) ? null : [Response::HTTP_FORBIDDEN, 'Accès refusé à ce livre.'];
    }

    /**
     * Lien signé valide pour un chapitre de ce livre, ou voter. Le contributeur validé par la signature est posé dans
     * l'attribut de requête « validatedContributorId ».
     *
     * @return array{0: int, 1: string}|null
     */
    public function canView(Book $book, Request $request, string $attribute = BookVoter::VIEW): ?array
    {
        [$expires, $signature, $chapterId, $contributorId] = $this->signatureParameters($request);

        if ($expires !== null && $signature !== null && $chapterId !== null && time() <= (int) $expires) {
            // 1. Signature propre au contributeur, puis 2. signature globale du chapitre
            $signed = [];
            if ($contributorId !== null) {
                $signed[] = "chapterId=$chapterId&contributorId=$contributorId&expires=$expires";
            }
            $signed[] = "chapterId=$chapterId&expires=$expires";
            foreach ($signed as $data) {
                if (hash_equals(hash_hmac('sha256', $data, $this->secret), (string) $signature) && $this->chapterBelongsTo($chapterId, $book)) {
                    if ($contributorId !== null) {
                        $request->attributes->set('validatedContributorId', $contributorId);
                    }

                    return null;
                }
            }
        }

        if ($this->security->getUser() !== null && $this->security->isGranted($attribute, $book)) {
            return null;
        }

        if ($expires !== null && $signature !== null && $chapterId !== null) {
            return [Response::HTTP_UNAUTHORIZED, time() > (int) $expires ? 'This sharing link has expired.' : 'Invalid signature or resource mismatch.'];
        }

        return [Response::HTTP_UNAUTHORIZED, 'Access denied. Missing or invalid signature.'];
    }

    /**
     * Paramètres du lien signé : requête, formulaire, corps JSON, en-têtes X-*, puis page d'origine (Referer).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} expires, signature, chapterId, contributorId
     */
    private function signatureParameters(Request $request): array
    {
        $json = json_decode((string) $request->getContent(), true);
        $referer = [];
        if (is_string($query = parse_url((string) $request->headers->get('Referer'), PHP_URL_QUERY))) {
            parse_str($query, $referer);
        }
        $sources = [
            $request->query->all(),
            $request->request->all(),
            is_array($json) ? $json : [],
            ['expires' => $request->headers->get('X-Expires'), 'signature' => $request->headers->get('X-Signature'),
                'chapterId' => $request->headers->get('X-Chapter-Id'), 'contributorId' => $request->headers->get('X-Contributor-Id')],
            $referer,
        ];

        $pick = function (array $keys) use ($sources): ?string {
            foreach ($sources as $source) {
                foreach ($keys as $key) {
                    if (isset($source[$key]) && is_scalar($source[$key])) {
                        return (string) $source[$key];
                    }
                }
            }

            return null;
        };

        return [$pick(['expires']), $pick(['signature']), $pick(['chapterId', 'chapter_id']), $pick(['contributorId', 'contributor_id'])];
    }

    private function chapterBelongsTo(string $chapterId, Book $book): bool
    {
        try {
            $chapter = $this->emProvider->getEntityManager()->getRepository(Chapter::class)->find(Uuid::fromString($chapterId));
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $chapter !== null && (string) $chapter->getBook()->getId() === (string) $book->getId();
    }
}
