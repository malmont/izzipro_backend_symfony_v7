<?php
// src/Entity/TranslatableInterface.php

namespace App\Entity;

interface TranslatableInterface
{
    public function getTranslatableFields(): array;
    public function getTranslationEntityClass(): string;
    public function addTranslation(object $translation): void;


    public function findTranslationByLocale(string $locale): ?object;

    public function getId(): ?int;
}