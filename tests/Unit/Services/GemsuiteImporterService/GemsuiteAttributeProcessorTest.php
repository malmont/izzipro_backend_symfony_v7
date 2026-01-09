<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Entity\ProductOption;
use App\Entity\ProductOptionValue;
use App\Entity\ProductVariant;
use App\Services\GemsuiteImporterService\GemsuiteAttributeProcessor;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GemsuiteAttributeProcessorTest extends TestCase
{
    private $logger;
    private $translationGenerator;
    private $em;
    private $optionRepo;
    private $valueRepo;
    private $variant;
    private $processor;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->translationGenerator = $this->createMock(TranslationGeneratorService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->optionRepo = $this->createMock(EntityRepository::class);
        $this->valueRepo = $this->createMock(EntityRepository::class);
        $this->variant = $this->createMock(ProductVariant::class);

        // Setup common EM behavior (mocking getRepository logic using a map or consecutive calls)
        // Setup common EM behavior (mocking getRepository logic)
        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) {
                if ($entityClass === ProductOption::class) {
                    return $this->optionRepo;
                }
                if ($entityClass === ProductOptionValue::class) {
                    return $this->valueRepo;
                }
                return null;
            });

        // Setup variant collection mock
        $collection = new ArrayCollection();
        $this->variant->method('getOptionValues')->willReturn($collection);
        $this->variant->method('addOptionValue')->will($this->returnSelf());

        $this->processor = new GemsuiteAttributeProcessor(
            $this->logger,
            $this->translationGenerator
        );
    }

    public function testProcessCreatesNewOptionAndValueForMappedAttribute(): void
    {
        // Data: Label '1' maps to 'Taille'
        $attributes = [
            ['labels' => '1', 'value' => 'L']
        ];

        // 1. Option Repo: findOneBy returns null (force creation)
        $this->optionRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['gemsuiteLabelId' => '1'])
            ->willReturn(null);

        // 2. Value Repo: findOneBy returns null (force creation)
        $this->valueRepo->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        // 3. Expectations
        // Persist Option
        $this->em->expects($this->exactly(2))
            ->method('persist')
            ->withConsecutive(
                [$this->isInstanceOf(ProductOption::class)],
                [$this->isInstanceOf(ProductOptionValue::class)]
            );

        // Flush twice (once after option, once after value)
        $this->em->expects($this->exactly(2))->method('flush');

        // Translations generated twice
        $this->translationGenerator->expects($this->exactly(2))
            ->method('generateTranslations');

        // Variant relationship added
        $this->variant->expects($this->once())
            ->method('addOptionValue')
            ->with($this->isInstanceOf(ProductOptionValue::class))
            ->will($this->returnSelf());

        $this->processor->process($this->em, $this->variant, $attributes);
    }

    public function testProcessCreatesFallbackOptionForUnknownLabel(): void
    {
        // Data: Label '999' is unknown
        $attributes = [
            ['labels' => '999', 'value' => 'CustomValue']
        ];

        $this->optionRepo->method('findOneBy')->willReturn(null);
        $this->valueRepo->method('findOneBy')->willReturn(null);

        // Capture the option passed to persist to verify name
        /** @var ProductOption|null $capturedOption */
        $capturedOption = null;
        $this->em->expects($this->atLeastOnce())
            ->method('persist')
            ->will($this->returnCallback(function ($entity) use (&$capturedOption) {
                if ($entity instanceof ProductOption) {
                    $capturedOption = $entity;
                }
            }));

        $this->processor->process($this->em, $this->variant, $attributes);

        $this->assertNotNull($capturedOption);
        /** @var ProductOption $capturedOption */
        $this->assertEquals('Option (Label 999)', $capturedOption->getName());
        $this->assertEquals('LABEL_999', $capturedOption->getCode());
    }

    public function testProcessIgnoresFilteredAttributes(): void
    {
        // Data: Label '4' is ignored in code; Empty value also ignored
        $attributes = [
            ['labels' => '4', 'value' => 'Ignored'],
            ['labels' => '', 'value' => 'NoLabel'],
            ['labels' => '2', 'value' => ''], // Empty value
        ];

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');
        $this->variant->expects($this->never())->method('addOptionValue');

        $this->processor->process($this->em, $this->variant, $attributes);
    }

    public function testProcessReusesExistingEntities(): void
    {
        $attributes = [
            ['labels' => '1', 'value' => 'L']
        ];

        $existingOption = new ProductOption();
        $existingOption->setName('Taille');

        $existingValue = new ProductOptionValue();
        $existingValue->setValue('L');

        // Repos find existing entities
        $this->optionRepo->expects($this->once())
            ->method('findOneBy')
            ->willReturn($existingOption);

        $this->valueRepo->expects($this->once())
            ->method('findOneBy')
            ->willReturn($existingValue);

        // Should NOT persist new ones
        $this->em->expects($this->never())->method('persist');
        // Still generates translations (business logic calls it unconditionally right now)
        $this->translationGenerator->expects($this->exactly(2))->method('generateTranslations');

        // Variant added
        $this->variant->expects($this->once())
            ->method('addOptionValue')
            ->with($existingValue)
            ->will($this->returnSelf());

        $this->processor->process($this->em, $this->variant, $attributes);
    }
}
