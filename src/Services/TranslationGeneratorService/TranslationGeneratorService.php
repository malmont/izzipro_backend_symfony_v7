<?php

namespace App\Services\TranslationGeneratorService;

use App\Entity\TranslatableInterface;
use Psr\Log\LoggerInterface;
use App\Services\GoogleTranslateService\GoogleTranslateService; 

class TranslationGeneratorService
{
    public function __construct(
        private GoogleTranslateService $translator,
        private LoggerInterface $logger
    ) {
    }

    public function generateTranslations(TranslatableInterface $entity): void
    {
        try {
            // ----------------------------------------------------
            // 1. GESTION DE LA TRADUCTION FRANÇAISE (SOURCE)
            // ----------------------------------------------------
            
            // Tente de trouver la traduction existante, ou en crée une nouvelle
            $frenchTranslation = $entity->findTranslationByLocale('fr');
            $newFrenchTranslation = false;

            if ($frenchTranslation === null) {
                $translationClass = $entity->getTranslationEntityClass();
                $frenchTranslation = new $translationClass();
                $this->setLocaleOrLanguage($frenchTranslation, 'fr');
                $newFrenchTranslation = true;
            }
            
            // Mise à jour des champs FR (même si elle existait)
            foreach ($entity->getTranslatableFields() as $field) {
                $getter = 'get' . ucfirst($field);
                $setter = 'set' . ucfirst($field);
                if (method_exists($entity, $getter) && method_exists($frenchTranslation, $setter)) {
                    $sourceValue = $entity->$getter();
                    if (is_string($sourceValue) || is_null($sourceValue)) {
                        $frenchTranslation->$setter($sourceValue);
                    }
                }
            }

            if ($newFrenchTranslation) {
                $entity->addTranslation($frenchTranslation);
            }


            // ----------------------------------------------------
            // 2. GESTION DE LA TRADUCTION ANGLAISE (CIBLE API)
            // ----------------------------------------------------
            
            // Tente de trouver la traduction existante, ou en crée une nouvelle
            $englishTranslation = $entity->findTranslationByLocale('en');
            $newEnglishTranslation = false;

            if ($englishTranslation === null) {
                $translationClass = $entity->getTranslationEntityClass();
                $englishTranslation = new $translationClass();
                $this->setLocaleOrLanguage($englishTranslation, 'en');
                $newEnglishTranslation = true;
            }

            // Mise à jour des champs EN (même si elle existait)
            foreach ($entity->getTranslatableFields() as $field) {
                $getter = 'get' . ucfirst($field);
                $setter = 'set' . ucfirst($field);
                
                if (method_exists($entity, $getter) && method_exists($englishTranslation, $setter)) {
                    // Utilise la valeur FR de l'entité comme source pour la traduction API
                    $sourceValue = $entity->$getter(); 
                    
                    if ($sourceValue === null) {
                        $englishTranslation->$setter(null);
                    } elseif (is_string($sourceValue) && trim($sourceValue) !== '') {
                        // 🚀 L'appel API de traduction est désormais exécuté à chaque mise à jour
                        $translatedText = $this->translator->translate($sourceValue, 'en', 'fr');
                        $englishTranslation->$setter($translatedText);
                    } else {
                        $englishTranslation->$setter(''); // Gère les chaînes vides
                    }
                }
            }
            
            if ($newEnglishTranslation) {
                $entity->addTranslation($englishTranslation);
            }

        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Erreur lors de la génération de traduction pour l\'entité %s (ID: %d): %s',
                get_class($entity),
                $entity->getId(),
                $e->getMessage()
            ));
        }
    }

    private function setLocaleOrLanguage(object $translationEntity, string $locale): void
    {
        if (method_exists($translationEntity, 'setLocale')) {
            $translationEntity->setLocale($locale);
        } elseif (method_exists($translationEntity, 'setLanguage')) {
            $translationEntity->setLanguage($locale);
        }
    }
}