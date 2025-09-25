<?php

namespace App\Form\Type;

use App\Form\DataTransformer\JsonToStringTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

class JsonTextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new JsonToStringTransformer());
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }
}