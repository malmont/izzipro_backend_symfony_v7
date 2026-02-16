<?php
// src/Services/GemsuiteImporterService/GemsuiteAttributeProcessor.php

namespace App\Services\GemsuiteImporterService;

use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
use App\Entity\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;

class GemsuiteAttributeProcessor
{
    private const LABEL_MAP = [
        '1' => ['name' => 'Taille',  'code' => 'taille'],
        '2' => ['name' => 'Couleur', 'code' => 'couleur'],
    ];

    public function __construct(
        private LoggerInterface $logger,
        private TranslationGeneratorService $translationGenerator
    ) {
    }

    public function process(EntityManagerInterface $em, ProductVariant $variant, array $attributes): void
    {
        $optionRepo = $em->getRepository(ProductOption::class);
        $valueRepo = $em->getRepository(ProductOptionValue::class);
        
        // On vide les anciennes options pour éviter les doublons lors d'une mise à jour
        $variant->getOptionValues()->clear(); 

        foreach ($attributes as $attribute) {
            $valueName = trim($attribute['value'] ?? '');
            // On récupère l'ID du label (ex: "2" pour Couleur)
            $labelId = trim(explode(',', $attribute['labels'] ?? '')[0] ?? '');
            
            // Filtres de sécurité
            if ($labelId === '4' || empty($valueName) || empty($labelId)) {
                continue; 
            }

            // 1. Trouver ou créer l'Option (ex: "Couleur")
            $productOption = $optionRepo->findOneBy(['gemsuiteLabelId' => $labelId]);
            if (!$productOption) {
                $productOption = new ProductOption();

                if (isset(self::LABEL_MAP[$labelId])) {
                    $mapping = self::LABEL_MAP[$labelId];
                    $productOption->setName($mapping['name']);
                    $productOption->setCode($mapping['code']);
                } else {
                    $productOption->setName('Option (Label ' . $labelId . ')');
                    $productOption->setCode('LABEL_' . $labelId);
                }
                
                $productOption->setGemsuiteLabelId($labelId);
                $em->persist($productOption);
                // Flush nécessaire ici pour avoir l'ID si on crée une valeur juste après
                $em->flush();
            }

            // Génération traduction pour l'Option
            $this->translationGenerator->generateTranslations($productOption);

            // 2. Trouver ou créer la Valeur (ex: "Noir")
            $productOptionValue = $valueRepo->findOneBy(['value' => $valueName, 'productOption' => $productOption]);
            if (!$productOptionValue) {
                $productOptionValue = new ProductOptionValue();
                $productOptionValue->setValue($valueName);
                $productOptionValue->setProductOption($productOption);
                $em->persist($productOptionValue);
                $em->flush();
            }

            $this->translationGenerator->generateTranslations($productOptionValue);

            $variant->addOptionValue($productOptionValue);
        }
        
    }
}