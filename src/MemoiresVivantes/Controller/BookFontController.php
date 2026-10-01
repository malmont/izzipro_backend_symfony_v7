<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Services\BookFontCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/memoires')]
class BookFontController extends AbstractController
{
    /** Polices proposées pour la mise en page d'un livre (champ « font » du livre, paramètre « font » des aperçus PDF) */
    #[Route('/book-fonts', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(BookFontCatalog::all());
    }
}
