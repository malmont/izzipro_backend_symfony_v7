<?php

namespace App\Tests\Unit\Services\GoogleTranslateService;

use App\Services\GoogleTranslateService\GoogleTranslateService;
use Google\Cloud\Translate\V2\TranslateClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GoogleTranslateServiceTest extends TestCase
{
    public function testConstructWithMissingKeyLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Google API Key is not configured'));

        new GoogleTranslateService(null, $logger);
    }

    public function testTranslateWithoutClientReturnsOriginalText(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $service = new GoogleTranslateService(null, $logger);

        $result = $service->translate('Hello', 'fr');
        $this->assertEquals('Hello', $result);
    }

    public function testTranslateWithValidKeyDelegatesToClient(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $client = $this->createMock(TranslateClient::class);

        $client->expects($this->once())
            ->method('translate')
            ->with('Hello', ['source' => 'en', 'target' => 'fr'])
            ->willReturn(['text' => 'Bonjour']);

        // Instantiate service with null key so it doesn't try to create a real client
        $service = new GoogleTranslateService(null, $logger);

        // Inject the mock client via Reflection
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('translateClient');
        $property->setAccessible(true);
        $property->setValue($service, $client);

        $result = $service->translate('Hello', 'fr', 'en');
        $this->assertEquals('Bonjour', $result);
    }

    public function testTranslateHandlesException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $client = $this->createMock(TranslateClient::class);

        $client->method('translate')
            ->willThrowException(new \Exception('API Error'));

        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Google Translate API error'));

        $service = $this->getMockBuilder(GoogleTranslateService::class)
            ->setConstructorArgs(['valid_key', $logger])
            ->onlyMethods(['createTranslateClient'])
            ->getMock();

        $service->method('createTranslateClient')->willReturn($client);

        $result = $service->translate('Hello', 'fr');
        $this->assertEquals('Hello', $result);
    }
}
