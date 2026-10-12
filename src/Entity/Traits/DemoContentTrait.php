<?php

namespace App\Entity\Traits;

use Doctrine\ORM\Mapping as ORM;

/** Implémentation de App\Entity\DemoContentInterface : colonne is_demo */
trait DemoContentTrait
{
    #[ORM\Column(name: 'is_demo', options: ['default' => false])]
    private bool $isDemo = false;

    public function isDemo(): bool
    {
        return $this->isDemo;
    }

    public function setIsDemo(bool $isDemo): static
    {
        $this->isDemo = $isDemo;

        return $this;
    }
}
