<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Libère les connexions à PostgreSQL à la fin de chaque test (12/10/2026). PHPUnit garde chaque objet de test jusqu'à
 * la fin de la suite : son navigateur (un noyau par requête, chacun avec ses connexions au site et à la base maître)
 * restait ouvert, et la suite approchait la limite de connexions du serveur, partagé avec la production (97 sur 100).
 * Les propriétés du test qui tiennent un navigateur ou une connexion sont fermées et vidées, puis les cycles collectés.
 */
final class ReleaseTestResourcesListener implements TestListener
{
    use TestListenerDefaultImplementation;

    public function endTest(Test $test, float $time): void
    {
        for ($class = new \ReflectionClass($test); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName() || !$property->isInitialized($test)) {
                    continue;
                }
                $value = $property->getValue($test);
                if ($value instanceof Connection) {
                    $value->close();
                } elseif (!$value instanceof KernelBrowser) {
                    continue;
                }
                $name = $property->getName();
                \Closure::bind(function () use ($name): void { unset($this->{$name}); }, $test, $class->getName())();
            }
        }
        gc_collect_cycles();
    }
}
