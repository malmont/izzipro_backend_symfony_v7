<?php

namespace App\Controller\Storage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class BucketSimulatorController extends AbstractController
{
    private string $bucketDir;

    public function __construct(string $projectDir)
    {
        $this->bucketDir = rtrim($projectDir, '/') . '/var/storage/public_bucket';
    }

    #[Route('/bucket-simulator/{path}', name: 'bucket_simulator', requirements: ['path' => '.+'], methods: ['GET', 'HEAD'])]
    #[Route('/assets/uploads/{path}', name: 'legacy_assets_uploads', requirements: ['path' => '.+'], methods: ['GET', 'HEAD'])]
    #[Route('/uploads/{path}', name: 'legacy_uploads', requirements: ['path' => '.+'], methods: ['GET', 'HEAD'])]
    public function serve(string $path, Request $request): Response
    {
        // Protection contre le Path Traversal
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new NotFoundHttpException('Fichier introuvable.');
        }

        // Si la requête provient d'une ancienne URL
        $route = $request->attributes->get('_route');
        if ($route === 'legacy_assets_uploads') {
            $path = 'assets/uploads/' . ltrim($path, '/');
        } elseif ($route === 'legacy_uploads') {
            $path = 'uploads/' . ltrim($path, '/');
        }

        $fullPath = $this->bucketDir . '/' . ltrim($path, '/');

        $realBase = realpath($this->bucketDir);
        $realPath = realpath($fullPath);

        if (!$realPath || !$realBase || !str_starts_with($realPath, $realBase) || !is_file($realPath)) {
            throw new NotFoundHttpException('Fichier introuvable dans le bucket public : ' . $path);
        }

        $response = new BinaryFileResponse($realPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        
        // Caching HTTP
        $response->setAutoEtag();
        $response->setPublic();
        $response->setMaxAge(86400);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }
}
