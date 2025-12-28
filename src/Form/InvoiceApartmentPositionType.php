<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\InvoiceAppartment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InvoiceApartmentPositionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $reservations = $options['reservations'];
        $this->getChoices($reservations, $choicesNumber, $choicesDesc, $choicesPersons, $choicesBeds);

        $builder
            ->add('number', ChoiceType::class, [
                'choices' => $choicesNumber,
                'label' => 'invoice.appartment.position.number',
            ])
            ->add('description_choices', HiddenType::class, [
                'mapped' => false,
                'data' => $choicesDesc,
            ])
            ->add('description', TextType::class, ['label' => 'invoice.appartment.position.description'])
            ->add('beds', ChoiceType::class, [
                'choices' => $choicesBeds,
                'label' => 'invoice.appartment.position.beds',
            ])
            ->add('persons', ChoiceType::class, [
                'choices' => $choicesPersons,
                'label' => 'invoice.appartment.position.persons',
            ])
            ->add('startDate', DateType::class, [
                'label' => 'invoice.appartment.position.startdate',
                'widget' => 'single_text',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'invoice.appartment.position.enddate',
                'widget' => 'single_text',
            ])
            ->add('price', NumberType::class, [
                'label' => 'invoice.appartment.position.price',
                'scale' => 2,
            ])
            ->add('vat', NumberType::class, [
                'label' => 'invoice.vat',
                'scale' => 2,
            ])
            ->add('includesVat', CheckboxType::class, [
                'label' => 'price.includesvat',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false,
            ])
            ->add('isFlatPrice', CheckboxType::class, [
                'label' => 'price.isflatprice',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvoiceAppartment::class,
        ]);
        $resolver->setRequired(['reservations']);
    }

    private function getChoices($reservations, &$choicesNumber, &$choicesDesc, &$choicesPersons, &$choicesBeds): void
    {
        $choicesNumber = [];
        $choicesDesc = '';

        $maxPersons = 0;
        $maxBeds = 0;
        $count = count($reservations);
        $i = 0;
        /* @var $reservation \App\Entity\Reservation */
        foreach ($reservations as $reservation) {
            $number = $reservation->getAppartment()->getNumber();
            $choicesNumber[$number] = $number;
            $choicesDesc .= $reservation->getAppartment()->getDescription();

            if ($reservation->getPersons() > $maxPersons) {
                $maxPersons = $reservation->getPersons();
            }
            if ($reservation->getAppartment()->getBedsMax() > $maxBeds) {
                $maxBeds = $reservation->getAppartment()->getBedsMax();
            }
            if ($i != $count - 1) {
                $choicesDesc .= '|';
            }
        }
        $choicesBeds = $this->makeArray($maxBeds);
        $choicesPersons = $this->makeArray($maxPersons);
    }

    private function makeArray(int $maxNumber)
    {
        $result = [];
        for ($i = 1; $i <= $maxNumber; ++$i) {
            $result[$i] = $i;
        }

        return $result;
    }
}
