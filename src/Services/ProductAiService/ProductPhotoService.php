<?php

namespace App\Services\ProductAiService;

use App\Entity\Product;
use App\Entity\ProductPicture;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photos d'un produit (page « Photos », 07/11/2026) : dépôt de plusieurs photos d'un coup dans le dossier des produits du site, photo
 * principale, suppression ; la galerie est la même que celle de la fiche EasyAdmin (`product.image` + `product_picture`).
 */
class ProductPhotoService
{
    public const MAX_FILES = 12;
    public const MAX_BYTES = 12_000_000;
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ContentAuditRecorder $audit, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    /** @return list<array{id: ?int, file: string, url: string, main: bool, source: string}> galerie : photo principale d'abord */
    public function gallery(Product $product, string $publicHost): array
    {
        $out = [];
        $main = trim((string) $product->getImage());
        if ($main !== '') {
            $out[] = ['id' => null, 'file' => $main, 'url' => $this->url($main, $publicHost), 'main' => true, 'source' => 'upload'];
        }
        foreach ($product->getPictures() as $pic) {
            $file = (string) $pic->getImageUrl();
            if ($file === '' || $file === $main) {
                continue;
            }
            $out[] = ['id' => (int) $pic->getId(), 'file' => $file, 'url' => $this->url($file, $publicHost), 'main' => false, 'source' => $pic->getSource()];
        }

        return $out;
    }

    /**
     * @param list<UploadedFile> $files
     * @return array{added: int, errors: list<string>}
     */
    public function add(Product $product, array $files, ?string $by): array
    {
        $em = $this->emProvider->getEntityManager();
        $dir = $this->projectDir . '/var/storage/public_bucket/assets/uploads/products';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['added' => 0, 'errors' => ['stockage des images indisponible']];
        }
        $added = 0;
        $errors = [];
        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $errors[] = 'fichier illisible';
                continue;
            }
            $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
            if (!in_array($mime, self::MIME_TYPES, true) || $file->getSize() > self::MAX_BYTES) {
                $errors[] = sprintf('%s : JPEG, PNG, WebP ou GIF de 12 Mo au plus', $file->getClientOriginalName());
                continue;
            }
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime];
            $name = bin2hex(random_bytes(12)) . '.' . $ext;
            $file->move($dir, $name);
            if (trim((string) $product->getImage()) === '' && $added === 0 && count($product->getPictures()) === 0) {
                $product->setImage($name); // première photo du produit : principale
            } else {
                $product->addPicture((new ProductPicture())->setImageUrl($name));
            }
            $added++;
        }
        $em->flush();
        if ($added > 0) {
            $this->audit->record('product', (int) $product->getId(), 'photos_add', [], ['added' => $added, 'by' => $by], ['pictures']);
        }

        return ['added' => $added, 'errors' => $errors];
    }

    /** Fait d'une photo de la galerie la photo principale (l'ancienne principale rejoint la galerie) */
    public function setMain(Product $product, int $pictureId): bool
    {
        $em = $this->emProvider->getEntityManager();
        foreach ($product->getPictures() as $pic) {
            if ((int) $pic->getId() === $pictureId) {
                $old = trim((string) $product->getImage());
                $product->setImage((string) $pic->getImageUrl());
                if ($old !== '') {
                    $pic->setImageUrl($old)->setSource('upload');
                } else {
                    $em->remove($pic);
                }
                $em->flush();

                return true;
            }
        }

        return false;
    }

    public function remove(Product $product, int $pictureId): bool
    {
        $em = $this->emProvider->getEntityManager();
        foreach ($product->getPictures() as $pic) {
            if ((int) $pic->getId() === $pictureId) {
                $em->remove($pic);
                $em->flush();

                return true;
            }
        }

        return false;
    }

    private function url(string $file, string $publicHost): string
    {
        return preg_match('#^https?://#', $file) ? $file : rtrim($publicHost, '/') . '/bucket-simulator/assets/uploads/products/' . ltrim($file, '/');
    }
}
