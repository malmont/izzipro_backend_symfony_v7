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
    // La "documentation" (table de mappage) vit ici, en un seul endroit.
    private const LABEL_MAP = [
        '1' => ['name' => 'Taille',  'code' => 'taille'],
        '2' => ['name' => 'Couleur', 'code' => 'couleur'],
        // Ajoutez ici tous vos autres labels...
    ];

    public function __construct(
        private LoggerInterface $logger,
        private TranslationGeneratorService $translationGenerator
    ) {
    }

    /**
     * Traite le tableau 'attributs' pour une variante donnée.
     * C'est l'ancienne méthode "processAttributes", maintenant dans son propre service.
     */
    public function process(EntityManagerInterface $em, ProductVariant $variant, array $attributes, string $defaultQuantity): void
    {
        $optionRepo = $em->getRepository(ProductOption::class);
        $valueRepo = $em->getRepository(ProductOptionValue::class);
        $variant->getOptionValues()->clear(); 

        $quantity = (int)($defaultQuantity ?? 0);

        foreach ($attributes as $attribute) {
            $valueName = trim($attribute['value'] ?? '');
            $labelId = trim(explode(',', $attribute['labels'] ?? '')[0] ?? '');
            
            if ($labelId === '4' || empty($valueName) || empty($labelId)) {
                continue; 
            }

            // 1. Trouver ou créer ProductOption
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
                $em->flush();
            }

            $this->translationGenerator->generateTranslations($productOption);
            $productOptionValue = $valueRepo->findOneBy(['value' => $valueName, 'productOption' => $productOption]);
            if (!$productOptionValue) {
                $productOptionValue = new ProductOptionValue();
                $productOptionValue->setValue($valueName);
                $productOptionValue->setProductOption($productOption);
                $em->persist($productOptionValue);
                $em->flush();
            }
            $this->translationGenerator->generateTranslations($productOptionValue);
            // 3. Lier la valeur à la variante
            $variant->addOptionValue($productOptionValue);
        }
        
        // 4. Mettre à jour la quantité
        $variant->setStockQuantity($quantity);
    }
}