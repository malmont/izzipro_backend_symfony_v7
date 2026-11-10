<?php

namespace App\Repository;

use App\Entity\GestionDocument;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<GestionDocument> */
class GestionDocumentRepository extends EntityRepository
{
    /** Lecture en cours sur le site (une seule à la fois) */
    public function findReading(): ?GestionDocument
    {
        return $this->findOneBy(['status' => GestionDocument::STATUS_READING]);
    }

    /** @return list<GestionDocument> documents non confirmés arrivés à expiration, et confirmés dont la cible a disparu */
    public function findPurgeable(\DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('(d.status != :confirmed AND d.expiresAt IS NOT NULL AND d.expiresAt < :now)')
            ->orWhere('(d.status = :confirmed AND d.commandeId IS NULL AND d.noteDeFraisId IS NULL AND d.fraisDePortId IS NULL AND d.campaignId IS NULL)')
            ->setParameter('confirmed', GestionDocument::STATUS_CONFIRMED)->setParameter('now', $now);

        return $qb->getQuery()->getResult();
    }

    /** Document confirmé portant ce numéro de facture pour ce fournisseur ou transporteur (doublon), sinon document en cours */
    public function findDuplicate(string $type, string $partyField, int $partyId, string $number, ?int $exceptId): ?GestionDocument
    {
        foreach ([GestionDocument::STATUS_CONFIRMED, GestionDocument::STATUS_EXTRACTED, GestionDocument::STATUS_READING] as $status) {
            foreach ($this->findBy(['type' => $type, 'status' => $status], ['id' => 'DESC']) as $doc) {
                if ($doc->getId() === $exceptId) {
                    continue;
                }
                $data = $doc->getConfirmed() ?? $doc->getExtraction() ?? [];
                $party = $data[$partyField] ?? [];
                $docNumber = (string) ($data['invoice']['number'] ?? '');
                if ((int) ($party['id'] ?? 0) === $partyId && $docNumber !== '' && mb_strtolower(trim($docNumber)) === mb_strtolower(trim($number))) {
                    return $doc;
                }
            }
        }

        return null;
    }

    /** @return array{0: list<GestionDocument>, 1: int} page et total */
    public function search(?string $type, ?string $status, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('d')->orderBy('d.id', 'DESC');
        if ($type !== null) {
            $qb->andWhere('d.type = :type')->setParameter('type', $type);
        }
        if ($status !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }
        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        return [$qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult(), $total];
    }
}
