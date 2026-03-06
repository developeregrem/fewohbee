<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Service\OnlineBookingConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class OnlineBookingConfigType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OnlineBookingConfigService $configService
    ) {
    }

    /** Build the online booking settings form with filtered template and status choices. */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $subsidiaries = $this->em->getRepository(Subsidiary::class)->findAll();
        $rooms = $this->em->getRepository(Appartment::class)->findAll();
        $statuses = $this->em->getRepository(ReservationStatus::class)->findAll();
        $origins = $this->em->getRepository(ReservationOrigin::class)->findAll();
        $templates = $this->em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_RESERVATION_EMAIL']) ?? [];

        $builder
            ->add('enabled', CheckboxType::class, [
                'required' => false,
                'label' => 'online_booking.settings.enabled',
                'label_attr' => ['class' => 'checkbox-switch'],
            ])
            ->add('bookingMode', ChoiceType::class, [
                'label' => 'online_booking.settings.booking_mode',
                'choices' => [
                    'online_booking.option.inquiry' => OnlineBookingConfig::BOOKING_MODE_INQUIRY,
                    'online_booking.option.booking' => OnlineBookingConfig::BOOKING_MODE_BOOKING,
                ],
            ])
            ->add('subsidiariesMode', ChoiceType::class, [
                'label' => 'online_booking.settings.subsidiaries_mode',
                'expanded' => true,
                'choices' => [
                    'online_booking.option.all' => OnlineBookingConfig::SUBSIDIARIES_MODE_ALL,
                    'online_booking.option.selected' => OnlineBookingConfig::SUBSIDIARIES_MODE_SELECTED,
                ],
            ])
            ->add('selectedSubsidiaryIds', ChoiceType::class, [
                'label' => 'online_booking.settings.selected_subsidiaries',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choice_translation_domain' => false,
                'choices' => $this->buildSubsidiaryChoices($subsidiaries),
            ])
            ->add('roomsMode', ChoiceType::class, [
                'label' => 'online_booking.settings.rooms_mode',
                'expanded' => true,
                'choices' => [
                    'online_booking.option.all' => OnlineBookingConfig::ROOMS_MODE_ALL,
                    'online_booking.option.selected' => OnlineBookingConfig::ROOMS_MODE_SELECTED,
                ],
            ])
            ->add('selectedRoomIds', ChoiceType::class, [
                'label' => 'online_booking.settings.selected_rooms',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choice_translation_domain' => false,
                'choices' => $this->buildRoomChoices($rooms),
            ])
            ->add('themePrimaryColor', ColorType::class, [
                'label' => 'online_booking.settings.theme_primary',
                'html5' => true,
            ])
            ->add('themeBackgroundColor', ColorType::class, [
                'label' => 'online_booking.settings.theme_background',
                'required' => false,
                'html5' => true,
            ])
            ->add('confirmationEmailTemplateId', ChoiceType::class, [
                'label' => 'online_booking.settings.confirmation_email_template',
                'required' => false,
                'placeholder' => 'online_booking.placeholder.select_template',
                'choice_translation_domain' => false,
                'choices' => $this->buildTemplateChoices($templates),
            ])
            ->add('inquiryReservationStatusId', ChoiceType::class, [
                'label' => 'online_booking.settings.inquiry_status',
                'required' => false,
                'placeholder' => 'online_booking.placeholder.select_status',
                'choice_translation_domain' => false,
                'choices' => $this->buildStatusChoices($statuses),
            ])
            ->add('bookingReservationStatusId', ChoiceType::class, [
                'label' => 'online_booking.settings.booking_status',
                'required' => false,
                'placeholder' => 'online_booking.placeholder.select_status',
                'choice_translation_domain' => false,
                'choices' => $this->buildStatusChoices($statuses),
            ])
            ->add('reservationOriginId', ChoiceType::class, [
                'label' => 'online_booking.settings.reservation_origin',
                'help' => 'online_booking.settings.reservation_origin_help',
                'help_html' => true,
                'required' => false,
                'placeholder' => 'online_booking.placeholder.select_origin',
                'choice_translation_domain' => false,
                'choices' => $this->buildOriginChoices($origins),
            ])
            ->add('paymentTerms', TextareaType::class, [
                'label' => 'online_booking.settings.payment_terms',
                'required' => false,
                'attr' => [
                    'rows' => 8,
                    'data-online-booking-settings-editor' => 'payment',
                ],
            ])
            ->add('cancellationTerms', TextareaType::class, [
                'label' => 'online_booking.settings.cancellation_terms',
                'required' => false,
                'attr' => [
                    'rows' => 8,
                    'data-online-booking-settings-editor' => 'cancellation',
                ],
            ])
            ->add('successMessageText', TextareaType::class, [
                'label' => 'online_booking.settings.success_message_text',
                'required' => false,
                'attr' => [
                    'rows' => 6,
                    'data-online-booking-settings-editor' => 'success-message',
                ],
            ])
            ->add('customCss', TextareaType::class, [
                'label' => 'online_booking.settings.custom_css',
                'help' => 'online_booking.settings.custom_css_help',
                'help_html' => true,
                'required' => false,
                'attr' => [
                    'rows' => 8,
                    'class' => 'font-monospace small',
                    'spellcheck' => 'false',
                    'placeholder' => ".fhb-booking-root {\n    font-family: 'Georgia', serif;\n}",
                ],
            ])
        ;
    }

    /** Attach the form to the config entity and register conditional validation rules. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OnlineBookingConfig::class,
            'constraints' => [
                new Callback([$this, 'validateConfig']),
            ],
        ]);
    }

    /** Validate required MVP fields when online booking is enabled. */
    public function validateConfig(OnlineBookingConfig $config, ExecutionContextInterface $context): void
    {
        if (!$config->isEnabled()) {
            return;
        }

        if (null === $config->getConfirmationEmailTemplateId()) {
            $context->buildViolation('online_booking.validation.confirmation_template_required')
                ->atPath('confirmationEmailTemplateId')
                ->setTranslationDomain('messages')
                ->addViolation();
        } elseif (null === $this->configService->getConfirmationEmailTemplate($config)) {
            $context->buildViolation('online_booking.validation.confirmation_template_invalid_type')
                ->atPath('confirmationEmailTemplateId')
                ->setTranslationDomain('messages')
                ->addViolation();
        }

        if (null === $config->getInquiryReservationStatusId() || null === $this->configService->getInquiryStatus($config)) {
            $context->buildViolation('online_booking.validation.inquiry_status_required')
                ->atPath('inquiryReservationStatusId')
                ->setTranslationDomain('messages')
                ->addViolation();
        }

        if (null === $config->getBookingReservationStatusId() || null === $this->configService->getBookingStatus($config)) {
            $context->buildViolation('online_booking.validation.booking_status_required')
                ->atPath('bookingReservationStatusId')
                ->setTranslationDomain('messages')
                ->addViolation();
        }

        if (null === $config->getReservationOriginId() || null === $this->configService->getReservationOrigin($config)) {
            $context->buildViolation('online_booking.validation.reservation_origin_required')
                ->atPath('reservationOriginId')
                ->setTranslationDomain('messages')
                ->addViolation();
        }
    }

    /**
     * Build subsidiary choices for the multiselect field.
     *
     * @param Subsidiary[] $subsidiaries
     * @return array<string, int>
     */
    private function buildSubsidiaryChoices(array $subsidiaries): array
    {
        $choices = [];
        foreach ($subsidiaries as $subsidiary) {
            $choices[(string) $subsidiary->getName()] = (int) $subsidiary->getId();
        }

        return $choices;
    }

    /**
     * Build room choices including subsidiary and category labels for admin clarity.
     *
     * @param Appartment[] $rooms
     * @return array<string, int>
     */
    private function buildRoomChoices(array $rooms): array
    {
        $choices = [];
        foreach ($rooms as $room) {
            $label = sprintf(
                '%s (%s) - %s',
                (string) $room->getNumber(),
                (string) $room->getObject()->getName(),
                (string) $room->getRoomCategory()?->getName() ?: (string) $room->getDescription()
            );
            $choices[$label] = (int) $room->getId();
        }

        return $choices;
    }

    /**
     * Build status choices used for inquiry and booking status mappings.
     *
     * @param ReservationStatus[] $statuses
     * @return array<string, int>
     */
    private function buildStatusChoices(array $statuses): array
    {
        $choices = [];
        foreach ($statuses as $status) {
            $choices[(string) $status->getName()] = (int) $status->getId();
        }

        return $choices;
    }

    /**
     * Build reservation origin choices so admins can map website bookings to an existing origin.
     *
     * @param ReservationOrigin[] $origins
     * @return array<string, int>
     */
    private function buildOriginChoices(array $origins): array
    {
        $choices = [];
        foreach ($origins as $origin) {
            $choices[(string) $origin->getName()] = (int) $origin->getId();
        }

        return $choices;
    }

    /**
     * Build template choices already filtered to reservation email templates.
     *
     * @param Template[] $templates
     * @return array<string, int>
     */
    private function buildTemplateChoices(array $templates): array
    {
        $choices = [];
        foreach ($templates as $template) {
            $choices[(string) $template->getName()] = (int) $template->getId();
        }

        return $choices;
    }
}
