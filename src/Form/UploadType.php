<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class UploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('files', FileType::class, [
            'label' => 'Выберите файлы', 'multiple' => true,
            'help' => 'До 5 файлов за раз, каждый до 50 МБ. Фото, видео, PDF и офисные документы.',
            'attr' => ['accept' => '.'.implode(',.', UploadPolicy::EXTENSIONS)],
            'constraints' => [new Assert\Count(min: 1, max: UploadPolicy::MAX_FILES), new Assert\All([UploadPolicy::fileConstraint()])],
        ]);
    }
}
