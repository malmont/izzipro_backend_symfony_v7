<?php
// src/Services/DefaultAssetSynchronizer.php

namespace App\Services;

use App\Entity\Carrier;
use App\Entity\Feature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

class DefaultAssetSynchronizer
{
    private Filesystem $filesystem;
    private string $projectDir;


    public function __construct(Filesystem $filesystem, KernelInterface $kernel)
    {
        $this->filesystem = $filesystem;
        $this->projectDir = $kernel->getProjectDir();
    }


    public function synchronize(EntityManagerInterface $tenantEm): void
    {
        $this->synchronizeCarriers($tenantEm);
        $this->synchronizeFeatures($tenantEm);
    }


    private function synchronizeCarriers(EntityManagerInterface $tenantEm): void
    {
        $carriers = $tenantEm->getRepository(Carrier::class)->findAll();
        $masterPath = $this->projectDir . '/public/assets/master_files/Carrier/';
        $uploadPath = $this->projectDir . '/public/assets/uploads/Carrier/';

        foreach ($carriers as $carrier) {
            $originalFilename = $carrier->getPhoto();

            if ($originalFilename && $this->filesystem->exists($masterPath . $originalFilename)) {
                $fileExtension = pathinfo($masterPath . $originalFilename, PATHINFO_EXTENSION);
                $newFilename = sha1(uniqid(mt_rand(), true)) . '.' . $fileExtension;
                $destinationFile = $uploadPath . $newFilename;

                $this->filesystem->copy($masterPath . $originalFilename, $destinationFile);
                $carrier->setPhoto($newFilename);
            }
        }
    }


    private function synchronizeFeatures(EntityManagerInterface $tenantEm): void
    {
        $features = $tenantEm->getRepository(Feature::class)->findAll();
        $masterPath = $this->projectDir . '/public/assets/master_files/Feature/';
        $uploadPath = $this->projectDir . '/public/assets/uploads/icons/';

        foreach ($features as $feature) {
            $originalFilename = $feature->getIconpath();

            if ($originalFilename && $this->filesystem->exists($masterPath . $originalFilename)) {
                $fileExtension = pathinfo($masterPath . $originalFilename, PATHINFO_EXTENSION);
                $newFilename = sha1(uniqid(mt_rand(), true)) . '.' . $fileExtension;
                $destinationFile = $uploadPath . $newFilename;

                $this->filesystem->copy($masterPath . $originalFilename, $destinationFile);
                $feature->setIconpath($newFilename); // On met à jour l'entité avec le nouveau nom
            }
        }
    }
}