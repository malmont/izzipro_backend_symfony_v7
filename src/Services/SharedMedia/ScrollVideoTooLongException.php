<?php

namespace App\Services\SharedMedia;

/** Vidéo trop longue pour être préparée pour le défilement (le poids du fichier préparé croît avec la durée) */
final class ScrollVideoTooLongException extends \RuntimeException
{
}
