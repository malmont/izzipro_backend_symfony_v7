<?php

namespace App\Tests\Unit\Services\TranslationGeneratorService;

use App\Entity\TranslatableInterface;
use App\Services\GoogleTranslateService\GoogleTranslateService;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TranslationGeneratorServiceTest extends TestCase
{
    private $translator;
    private $logger;
    private $translationGeneratorService;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(GoogleTranslateService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->translationGeneratorService = new TranslationGeneratorService(
            $this->translator,
            $this->logger
        );
    }

    public function testGenerateTranslationsCreatesNewTranslations(): void
    {
        $entity = new class implements TranslatableInterface {
            public $translations = [];
            public $name = 'Source Name';

            public function getId(): ?int
            {
                return 1;
            }
            public function findTranslationByLocale(string $locale): ?object
            {
                return $this->translations[$locale] ?? null;
            }
            public function getTranslationEntityClass(): string
            {
                return MockTranslation::class;
            }
            public function getTranslatableFields(): array
            {
                return ['name'];
            }
            public function addTranslation(object $translation): void
            {
                if (method_exists($translation, 'getLocale')) { // Mock logic
                    $this->translations[$translation->getLocale()] = $translation;
                } elseif (isset($translation->locale)) {
                    $this->translations[$translation->locale] = $translation;
                }
            }
            public function getName()
            {
                return $this->name;
            }
        };

        // Expect translation call for EN
        $this->translator->expects($this->once())
            ->method('translate')
            ->with('Source Name', 'en', 'fr')
            ->willReturn('Translated Name');

        $this->translationGeneratorService->generateTranslations($entity);

        $this->assertArrayHasKey('fr', $entity->translations);
        $this->assertArrayHasKey('en', $entity->translations);

        $this->assertEquals('Source Name', $entity->translations['fr']->getName());
        $this->assertEquals('Translated Name', $entity->translations['en']->getName());
    }

    public function testGenerateTranslationsUpdatesExistingTranslations(): void
    {
        $frTranslation = new MockTranslation();
        $frTranslation->setLocale('fr');
        $frTranslation->setName('Old FR');

        $enTranslation = new MockTranslation();
        $enTranslation->setLocale('en');
        $enTranslation->setName('Old EN');

        $entity = new class($frTranslation, $enTranslation) implements TranslatableInterface {
            public $translations = [];
            public $name = 'New Source';

            public function __construct($fr, $en)
            {
                $this->translations['fr'] = $fr;
                $this->translations['en'] = $en;
            }

            public function getId(): ?int
            {
                return 1;
            }
            public function findTranslationByLocale(string $locale): ?object
            {
                return $this->translations[$locale] ?? null;
            }
            public function getTranslationEntityClass(): string
            {
                return MockTranslation::class;
            }
            public function getTranslatableFields(): array
            {
                return ['name'];
            }
            public function addTranslation(object $translation): void {}
            public function getName()
            {
                return $this->name;
            }
        };

        // Expect translation call for EN
        $this->translator->expects($this->once())
            ->method('translate')
            ->with('New Source', 'en', 'fr')
            ->willReturn('New Translated');

        $this->translationGeneratorService->generateTranslations($entity);

        $this->assertEquals('New Source', $entity->findTranslationByLocale('fr')->getName());
        $this->assertEquals('New Translated', $entity->findTranslationByLocale('en')->getName());
    }

    public function testGenerateTranslationsHandlesErrors(): void
    {
        $entity = new class implements TranslatableInterface {
            public function getId(): ?int
            {
                return 123;
            }
            public function findTranslationByLocale(string $locale): ?object
            {
                throw new \Exception('Test Error');
            }
            public function getTranslationEntityClass(): string
            {
                return '';
            }
            public function getTranslatableFields(): array
            {
                return [];
            }
            public function addTranslation(object $translation): void {}
        };

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Test Error'));

        $this->translationGeneratorService->generateTranslations($entity);
    }
}

class MockTranslation
{
    public $locale;
    public $name;
    public function setLocale($l)
    {
        $this->locale = $l;
    }
    public function getLocale()
    {
        return $this->locale;
    }
    public function setName($n)
    {
        $this->name = $n;
    }
    public function getName()
    {
        return $this->name;
    }
}
