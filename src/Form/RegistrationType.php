<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Электронная почта',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180],
                'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Пароль', 'help' => 'От 12 до 72 символов.',
                'attr' => ['autocomplete' => 'new-password', 'minlength' => 12, 'maxlength' => 72],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 12, max: 72), new Assert\Length(max: 72, countUnit: Assert\Length::COUNT_BYTES, maxMessage: 'Пароль должен занимать не более 72 байт.')],
            ]);
    }
}
