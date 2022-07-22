<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\Role;
use App\Entity\User;
use App\Validator\UsernameAvailable;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'user.username',
                'constraints' => [
                    new UsernameAvailable()
                ]
            ])
            ->add('firstname', TextType::class, [
                'label' => 'user.firstname'
            ])
            ->add('lastname', TextType::class, [
                'label' => 'user.lastname'
            ])
            ->add('email')
            ->add('password', PasswordType::class, [
                'label' => 'user.password',
                'empty_data' => '',
                'required' => true,
            ])
            ->add('role', EntityType::class, [
                'class' => Role::class,
                'choice_label' => 'name'
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'user.active',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
