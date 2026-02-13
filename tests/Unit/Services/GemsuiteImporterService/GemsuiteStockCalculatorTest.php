<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Services\GemsuiteImporterService\GemsuiteStockCalculator;
use PHPUnit\Framework\TestCase;

class GemsuiteStockCalculatorTest extends TestCase
{
    private GemsuiteStockCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GemsuiteStockCalculator();
    }

    public function testCalculateTotalStockWithEmptyData(): void
    {
        $data = [];
        $result = $this->calculator->calculateTotalStock($data);
        $this->assertEquals(0, $result);
    }

    public function testCalculateTotalStockWithQuantiteArray(): void
    {
        $data = [
            'quantite' => [
                ['quantite' => 10],
                ['quantite' => 5.5], // Cas float
                ['quantite' => -2]   // Cas négatif
            ]
        ];

        // 10 + 5.5 - 2 = 13.5 -> cast int -> 13
        $result = $this->calculator->calculateTotalStock($data);
        $this->assertEquals(13, $result);
    }

    public function testCalculateTotalStockHandlesMissingKeys(): void
    {
        $data = [
            'quantite' => [
                ['quantite' => 10],
                ['other_key' => 999] // Doit être traité comme 0
            ]
        ];

        $result = $this->calculator->calculateTotalStock($data);
        $this->assertEquals(10, $result);
    }

    public function testCalculateTotalStockHandlesNulls(): void
    {
        $data = [
            'quantite' => [
                ['quantite' => null],
                ['quantite' => 5]
            ]
        ];

        $result = $this->calculator->calculateTotalStock($data);
        $this->assertEquals(5, $result);
    }
}
