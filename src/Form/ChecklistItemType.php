<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ChecklistItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ChecklistItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Новый пункт чек-листа',
                'empty_data' => '',
                'attr' => ['maxlength' => 200, 'placeholder' => 'Добавить пункт…', 'autocomplete' => 'off'],
            ])
            ->add('context', HiddenType::class, [
                'mapped' => false, 'data' => $options['context'],
                'constraints' => [new Assert\Choice(['task', 'block', 'overview'])],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ChecklistItem::class, 'context' => 'task']);
        $resolver->setAllowedValues('context', ['task', 'block', 'overview']);
    }
}
