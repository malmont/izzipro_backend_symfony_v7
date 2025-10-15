<?php


namespace App\Services\TranslationGeneratorService;

use App\Entity\TranslatableInterface;
// use App\Services\DeepLTranslateService\DeepLTranslateService;
use Psr\Log\LoggerInterface;
use App\Services\GoogleTranslateService\GoogleTranslateService; 

class TranslationGeneratorService
{
    public function __construct(
        // private DeepLTranslateService $translator,
        private GoogleTranslateService $translator,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Génère les traductions FR (copie) et EN (API) pour n'importe quelle entité
     * qui implémente TranslatableInterface.
     */
    public function generateTranslations(TranslatableInterface $entity): void
    {
        try {
            // --- Partie Française (Copie) ---
            if ($entity->findTranslationByLocale('fr') === null) {
                $translationClass = $entity->getTranslationEntityClass();
                $frenchTranslation = new $translationClass();
                $this->setLocaleOrLanguage($frenchTranslation, 'fr');
                
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
                $entity->addTranslation($frenchTranslation);
            }

            // --- Partie Anglaise (Traduction API) ---
            if ($entity->findTranslationByLocale('en') === null) {
                $translationClass = $entity->getTranslationEntityClass();
                $englishTranslation = new $translationClass();
                $this->setLocaleOrLanguage($englishTranslation, 'en');

                foreach ($entity->getTranslatableFields() as $field) {
                    $getter = 'get' . ucfirst($field);
                    $setter = 'set' . ucfirst($field);
                    if (method_exists($entity, $getter) && method_exists($englishTranslation, $setter)) {
                        $sourceValue = $entity->$getter();
                        if ($sourceValue === null) {
                            $englishTranslation->$setter(null);
                        } elseif (is_string($sourceValue) && trim($sourceValue) !== '') {
                            $translatedText = $this->translator->translate($sourceValue, 'en', 'fr');
                            $englishTranslation->$setter($translatedText);
                        }
                    }
                }
                $entity->addTranslation($englishTranslation);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Erreur lors de la génération de traduction pour l\'entité %s (ID: %d): %s',
                get_class($entity),
                $entity->getId(),
                $e->getMessage()
            ));
            // On ne bloque pas tout le processus d'importation si une seule traduction échoue.
        }
    }

    /**
     * Gère la compatibilité setLocale/setLanguage.
     */
    private function setLocaleOrLanguage(object $translationEntity, string $locale): void
    {
        if (method_exists($translationEntity, 'setLocale')) {
            $translationEntity->setLocale($locale);
        } elseif (method_exists($translationEntity, 'setLanguage')) {
            $translationEntity->setLanguage($locale);
        }
    }
}