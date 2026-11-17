<?php

namespace App\LossPrevention\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Une pièce attendue d'un comptage fermé (lot L4-d), et si elle a été lue. Écrite une fois, à la fermeture ; jamais changée ni
 * supprimée (sauf l'exercice). Sert à dire « absente à deux comptages de suite qui la couvrent ».
 */
#[ORM\Entity]
#[ORM\Table(name: 'loss_count_piece')]
#[ORM\Index(name: 'idx_loss_count_piece_piece', columns: ['piece_id', 'count_id'])]
class LossCountPiece
{
    #[ORM\Id]
    #[ORM\Column(name: 'count_id')]
    private int $countId;

    #[ORM\Id]
    #[ORM\Column(name: 'piece_id')]
    private int $pieceId;

    #[ORM\Column]
    private bool $found = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $exercise = false;
}
