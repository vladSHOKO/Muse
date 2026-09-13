<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Task;
use App\Entity\TaskBlock;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TaskType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Task $task */
        $task = $options['data'];
        $builder
            ->add('title', TextType::class, ['label' => 'Название', 'empty_data' => '', 'attr' => ['maxlength' => 200, 'autofocus' => true]])
            ->add('description', TextareaType::class, ['label' => 'Описание', 'required' => false, 'attr' => ['rows' => 7, 'maxlength' => 50000]])
            ->add('deadline', DateTimeType::class, [
                'label' => 'Дедлайн (МСК)', 'required' => false,
                'widget' => 'single_text', 'input' => 'datetime_immutable',
                'model_timezone' => 'UTC', 'view_timezone' => 'Europe/Moscow',
                'help' => 'Дата и время по Москве. Оставьте пустым, если срока нет.',
                'invalid_message' => 'Введите корректные дату и время.',
            ]);
        if ($task->getId() !== null) {
            $builder->add('completed', CheckboxType::class, ['label' => 'Задача выполнена', 'required' => false]);
        }
        $builder->add('priority', ChoiceType::class, [
            'label' => 'Приоритет',
            'required' => false,
            'placeholder' => 'Без приоритета',
            'expanded' => true,
            'choices' => ['1 — наивысший' => 1, '2' => 2, '3' => 3, '4' => 4, '5 — наименьший' => 5],
            'help' => 'Сначала задачи с приоритетом 1, затем 2–5, после них — без приоритета.',
            'invalid_message' => 'Выберите приоритет от 1 до 5 или «Без приоритета».',
        ]);
        $builder->add('block', EntityType::class, [
            'class' => TaskBlock::class, 'choice_label' => 'title', 'label' => 'Блок задач',
            'required' => true, 'placeholder' => 'Выберите блок',
            'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('b')
                ->where('b.owner = :owner')->setParameter('owner', $task->getOwner())->orderBy('b.title', 'ASC'),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => Task::class]); }
}
