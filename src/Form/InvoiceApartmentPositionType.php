<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\InvoiceAppartment;
use App\Entity\Reservation;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

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
                // The entity setter normalizes null to zero; validate the submitted value here.
                'constraints' => [new NotNull()],
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
            ->add('isPerRoom', CheckboxType::class, [
                'label' => 'price.perroom',
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

    /**
     * Uninitialized output arguments enter as null and are always assigned before returning.
     *
     * @param array<Reservation>|Collection<int, Reservation> $reservations
     * @param array<array-key, string>|null $choicesNumber
     * @param array<int, int>|null $choicesPersons
     * @param array<int, int>|null $choicesBeds
     * @param-out array<array-key, string> $choicesNumber
     * @param-out string $choicesDesc
     * @param-out array<int, int> $choicesPersons
     * @param-out array<int, int> $choicesBeds
     */
    private function getChoices(
        array|Collection $reservations,
        ?array &$choicesNumber,
        ?string &$choicesDesc,
        ?array &$choicesPersons,
        ?array &$choicesBeds,
    ): void
    {
        $choicesNumber = [];
        $choicesDesc = '';

        $maxPersons = 0;
        $maxBeds = 0;
        $count = count($reservations);
        $i = 0;
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

    /** @return array<int, int> */
    private function makeArray(int $maxNumber): array
    {
        $result = [];
        for ($i = 1; $i <= $maxNumber; ++$i) {
            $result[$i] = $i;
        }

        return $result;
    }
}
