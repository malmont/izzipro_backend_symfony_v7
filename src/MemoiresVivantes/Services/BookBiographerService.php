<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Exception\ApiErrorException;
use App\Security\TeamRoles;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Biographe affecté au livre, lot MV-AFFECTATION (13/11/2026) : un membre de l'équipe (ROLE_MEMOIRES_BIOGRAPHE), choisi
 * explicitement dans la fiche par un administrateur ou un biographe ; journalisé (content_audit_log, book_biographer,
 * assign | unassign). Jamais déduit d'un rendez-vous ni posé automatiquement. Contrat : docs/memoires-vivantes.md, « Équipe ».
 */
final class BookBiographerService
{
    public const AUDIT_RESOURCE = 'book_biographer';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ContentAuditRecorder $audit
    ) {
    }

    /**
     * @throws ApiErrorException FORBIDDEN (403 : l'éditeur n'est pas de l'équipe et change la valeur ; renvoyer la valeur actuelle
     *                           est toléré et ignoré), BIOGRAPHER_INVALID (400 : compte inconnu ou sans le rôle de biographe)
     * @return bool vrai si le livre a changé
     */
    public function assign(Book $book, ?int $biographerId, User $editor): bool
    {
        $current = $book->getBiographer()?->getId();
        if (!TeamRoles::managesMemoires($editor)) { // administrateur du site ou responsable des biographes (14/11/2026) ; un biographe n'affecte pas
            if ($current === $biographerId) {
                return false; // un front qui renvoie la fiche entière : rien à faire
            }
            throw new ApiErrorException('FORBIDDEN', 'Seul un administrateur ou le responsable des biographes affecte le biographe d\'un livre.', 403);
        }
        $target = null;
        if ($biographerId !== null) {
            $target = $this->emProvider->getEntityManager()->getRepository(User::class)->find($biographerId);
            if (!$target instanceof User || !in_array(TeamRoles::MEMOIRES_BIOGRAPHE, $target->getRoles(), true)) {
                throw new ApiErrorException('BIOGRAPHER_INVALID', 'Ce compte n\'existe pas ou n\'est pas biographe de l\'équipe Mémoires Vivantes.', 400);
            }
        }
        if ($current === $biographerId) {
            return false;
        }
        $before = self::describe($book->getBiographer());
        $book->setBiographer($target);
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(self::AUDIT_RESOURCE, (string) $book->getId(), $target === null ? 'unassign' : 'assign', $before, self::describe($target), ['biographerId']);

        return true;
    }

    /**
     * Un biographe qui crée un livre en devient le biographe affecté (14/11/2026) : sans cela son livre serait « sans biographe » dans
     * les filtres du responsable. Au journal comme toute affectation. Rien pour un autre créateur, ni si le livre a déjà un biographe.
     * Sert aussi au propriétaire d'un livre web devenu biographe (repère `family_split`, `AppFamilySplit`).
     *
     * @return bool vrai si le livre a changé
     */
    public function assignCreator(Book $book, User $creator, string $mark = 'creator'): bool
    {
        if ($book->getBiographer() !== null || !in_array(TeamRoles::MEMOIRES_BIOGRAPHE, $creator->getRoles(), true)) {
            return false;
        }
        $book->setBiographer($creator);
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(self::AUDIT_RESOURCE, (string) $book->getId(), 'assign', null, self::describe($creator), ['biographerId', $mark]);

        return true;
    }

    /** @return array{id: int, fullName: string}|null forme rendue dans les lectures de livre */
    public static function describe(?User $user): ?array
    {
        return $user === null ? null : ['id' => (int) $user->getId(), 'fullName' => trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''))];
    }
}
