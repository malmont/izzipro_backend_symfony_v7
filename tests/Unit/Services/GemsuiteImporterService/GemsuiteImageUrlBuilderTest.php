<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use PHPUnit\Framework\TestCase;

class GemsuiteImageUrlBuilderTest extends TestCase
{
    private $builder;

    protected function setUp(): void
    {
        $this->builder = new GemsuiteImageUrlBuilder();
    }

    public function testBuildUrlReturnsEmptyStringIfIdentifierMissing(): void
    {
        $this->assertEquals('', $this->builder->buildUrl(null, 'path/to/image.jpg'));
        $this->assertEquals('', $this->builder->buildUrl('', 'path/to/image.jpg'));
    }

    public function testBuildUrlReturnsEmptyStringIfPathMissing(): void
    {
        $this->assertEquals('', $this->builder->buildUrl('identifier', null));
        $this->assertEquals('', $this->builder->buildUrl('identifier', ''));
    }

    public function testBuildUrlReturnsCorrectUrl(): void
    {
        $identifier = 'my-company';
        $path = 'images/logo.png';
        $expected = 'https://app.gem-books.com/?layout=image&d=my-company&filename=images/logo.png';

        $this->assertEquals($expected, $this->builder->buildUrl($identifier, $path));
    }
}
