<?php

namespace App\Form;

use App\Entity\InvoiceSettingsData;
use App\Repository\InvoiceSettingsDataRepository;
use App\Repository\SubsidiaryRepository;
use App\Service\EInvoice\EInvoiceProfileRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Bic;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Country;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class InvoiceSettingsType extends AbstractType
{
    /** Applies to every branch that has no issuer of its own. */
    private const SCOPE_DEFAULT = 'default';

    /** Kept on file but not used anywhere. */
    private const SCOPE_UNUSED = 'unused';

    private const SCOPE_SUBSIDIARY_PREFIX = 'subsidiary:';

    public function __construct(
        private EInvoiceProfileRegistry $profileRegistry,
        private SubsidiaryRepository $subsidiaryRepository,
        private InvoiceSettingsDataRepository $settingsRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('einvoiceProfile', ChoiceType::class, [
                'label' => 'invoice.settings.einvoiceProfile',
                'choices' => $this->profileRegistry->getProfileChoices(),
                'help' => 'invoice.settings.einvoiceProfile.help',
                'attr' => [
                    'data-einvoice-settings-target' => 'profileSelect',
                    'data-action' => 'einvoice-settings#profileChanged',
                ],
            ])
            ->add('companyName', TextType::class, [
                'label' => 'invoice.settings.companyName',
            ])
            ->add('taxNumber', TextType::class, [
                'label' => 'invoice.settings.taxNumber',
                'required' => false,
            ])
            ->add('vatID', TextType::class, [
                'label' => 'invoice.settings.vatID',
                'required' => false,
                'help' => 'invoice.settings.vatID.hint',
                'constraints' => [
                    new Callback([$this, 'validateVatIDCountry']),
                ],
            ])
            ->add('registrationNumber', TextType::class, [
                'label' => 'invoice.settings.registrationNumber',
                'help' => 'invoice.settings.registrationNumber.hint',
                'required' => false,
            ])
            ->add('contactName', TextType::class, [
                'label' => 'invoice.settings.contactName',
                'required' => false,
                'row_attr' => ['class' => 'js-xrechnung-required'],
            ])
            ->add('contactDepartment', TextType::class, [
                'label' => 'invoice.settings.contactDepartment',
                'required' => false,
            ])
            ->add('contactPhone', TextType::class, [
                'label' => 'invoice.settings.contactPhone',
                'required' => false,
                'row_attr' => ['class' => 'js-xrechnung-required'],
            ])
            ->add('contactMail', TextType::class, [
                'label' => 'invoice.settings.contactMail',
                'required' => false,
                'row_attr' => ['class' => 'js-xrechnung-required'],
                'constraints' => [
                    new Email(),
                ],
            ])
            ->add('companyInvoiceMail', TextType::class, [
                'label' => 'invoice.settings.companyInvoiceMail',
                'help' => 'invoice.settings.help.invoiceMail',
                'constraints' => [
                    new Email(),
                ],
            ])
            ->add('companyAddress', TextType::class, [
                'label' => 'invoice.settings.companyAddress',
            ])
            ->add('companyPostCode', TextType::class, [
                'label' => 'invoice.settings.companyPostCode',
            ])
            ->add('companyCity', TextType::class, [
                'label' => 'invoice.settings.companyCity',
            ])
            ->add('companyCountry', CountryType::class, [
                'label' => 'invoice.settings.companyCountry',
            ])
            ->add('accountName', TextType::class, [
                'label' => 'invoice.settings.accountName',
            ])
            ->add('accountIBAN', TextType::class, [
                'label' => 'invoice.settings.accountIBAN',
                'constraints' => [
                    new Iban(),
                ],
            ])
            ->add('accountBIC', TextType::class, [
                'label' => 'invoice.settings.accountBIC',
                'constraints' => [
                    new Bic(),
                ],
                'required' => false,
            ])
            ->add('paymentTerms', TextareaType::class, [
                'label' => 'invoice.settings.paymentTerms',
                'required' => false,
            ])
            ->add('paymentDueDays', IntegerType::class, [
                'label' => 'invoice.settings.paymentDueDays',
                'required' => false,
            ])
            ->add('creditorReference', TextType::class, [
                'label' => 'invoice.settings.creditorReference.label',
                'help' => 'invoice.settings.creditorReference.hint',
                'required' => false,
            ])
            // One question instead of two flags: "isActive" alone became misleading as soon
            // as branches could have their own issuer — an "inactive" record assigned to a
            // branch is very much in use. The scope makes the coverage explicit and keeps
            // the two settings from contradicting each other.
            ->add('scope', ChoiceType::class, [
                'label' => 'invoice.settings.scope',
                'help' => 'invoice.settings.scope.help',
                'mapped' => false,
                'expanded' => false,
                'choices' => $this->buildScopeChoices(),
                'constraints' => [
                    new Callback($this->validateScope(...)),
                ],
            ])
        ;

        // POST_SET_DATA, not PRE_SET_DATA: the children are only populated after the parent
        // has its data, so a value written in PRE_SET_DATA is overwritten again and the
        // select would silently fall back to its first option.
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            $settings = $event->getData();
            $form = $event->getForm();
            if (!$settings instanceof InvoiceSettingsData || !$form->has('scope')) {
                return;
            }

            $form->get('scope')->setData($this->scopeOf($settings));
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $settings = $event->getData();
            $form = $event->getForm();
            if (!$settings instanceof InvoiceSettingsData || !$form->has('scope')) {
                return;
            }

            $this->applyScope($settings, (string) $form->get('scope')->getData());
        });
    }

    /**
     * The three states an issuer record can be in, as one flat choice list:
     * the default fallback, a specific branch, or parked and unused.
     *
     * @return array<string, string>
     */
    private function buildScopeChoices(): array
    {
        $choices = [
            'invoice.settings.scope.default' => self::SCOPE_DEFAULT,
        ];

        foreach ($this->subsidiaryRepository->findBy([], ['name' => 'ASC']) as $subsidiary) {
            $choices[$subsidiary->getName()] = self::SCOPE_SUBSIDIARY_PREFIX.$subsidiary->getId();
        }

        $choices['invoice.settings.scope.unused'] = self::SCOPE_UNUSED;

        return $choices;
    }

    private function scopeOf(InvoiceSettingsData $settings): string
    {
        $subsidiary = $settings->getSubsidiary();
        if (null !== $subsidiary) {
            return self::SCOPE_SUBSIDIARY_PREFIX.$subsidiary->getId();
        }

        // A brand new record defaults to being the fallback, which is what a first-time
        // setup needs; an existing record without branch and without the flag is parked.
        if ($settings->isActive() || null === $settings->getId()) {
            return self::SCOPE_DEFAULT;
        }

        return self::SCOPE_UNUSED;
    }

    private function applyScope(InvoiceSettingsData $settings, string $scope): void
    {
        if (str_starts_with($scope, self::SCOPE_SUBSIDIARY_PREFIX)) {
            $id = (int) substr($scope, strlen(self::SCOPE_SUBSIDIARY_PREFIX));
            $settings->setSubsidiary($this->subsidiaryRepository->find($id));
            // A branch-specific record is never the global fallback.
            $settings->setIsActive(false);

            return;
        }

        $settings->setSubsidiary(null);
        $settings->setIsActive(self::SCOPE_DEFAULT === $scope);
    }

    /**
     * Rejects claiming a branch that another issuer record already covers. The database
     * carries a unique index for this too; this check exists so the message lands on the
     * field the user just changed instead of surfacing as a generic form error.
     */
    private function validateScope(?string $scope, ExecutionContextInterface $context): void
    {
        if (null === $scope || !str_starts_with($scope, self::SCOPE_SUBSIDIARY_PREFIX)) {
            return;
        }

        $settings = $context->getRoot()->getData();
        $subsidiaryId = (int) substr($scope, strlen(self::SCOPE_SUBSIDIARY_PREFIX));

        $existing = $this->settingsRepository->findOneBy(['subsidiary' => $subsidiaryId]);
        if (null === $existing) {
            return;
        }

        if (!$settings instanceof InvoiceSettingsData || $existing->getId() !== $settings->getId()) {
            $context->buildViolation('invoice.settings.subsidiary.taken')->addViolation();
        }
    }

    public function validateVatIDCountry($vatID, ExecutionContextInterface $context): void
    {
        if ($vatID) {
            $countryCode = substr($vatID, 0, 2);
            $countryConstraint = new Country();
            $violations = $context->getValidator()->validate($countryCode, $countryConstraint);

            if (count($violations) > 0) {
                $context->buildViolation('form.vatid.invalid_country')
                    ->atPath('vatID')
                    ->addViolation();
            }
        }
    }

    // Enforces the seller-side e-invoice requirements already at settings save time, so the user
    // does not only discover them when generating an invoice. Payment-means-dependent rules
    // (IBAN/BIC/creditor id) are not checked here as they depend on the individual invoice.
    public function validateProfileRequirements(InvoiceSettingsData $settings, ExecutionContextInterface $context): void
    {
        // BR-CO-26: e-invoices need a seller VAT id (BT-31) or a legal registration number (BT-30);
        // the German tax number (BT-32) alone does not satisfy the rule.
        if (empty($settings->getVatID()) && empty($settings->getRegistrationNumber())) {
            $context->buildViolation('invoice.settings.selleridentifier.required')
                ->atPath('vatID')
                ->setTranslationDomain('messages')
                ->addViolation();
        }

        // BR-DE-5/6/7: XRechnung additionally requires the seller contact (name, phone, email).
        if ('xrechnung' !== $settings->getEinvoiceProfile()) {
            return;
        }

        foreach (['contactName', 'contactPhone', 'contactMail'] as $field) {
            $getter = 'get'.ucfirst($field);
            if (empty($settings->$getter())) {
                $context->buildViolation('invoice.settings.xrechnung.contact.error')
                    ->atPath($field)
                    ->setTranslationDomain('messages')
                    ->addViolation();
            }
        }
    }

    // Ensures either payment terms or payment due days is set.
    public function validatePaymentTerms(InvoiceSettingsData $settings, ExecutionContextInterface $context): void
    {
        if (empty($settings->getPaymentDueDays()) && empty($settings->getPaymentTerms())) {
            $context->buildViolation('invoice.settings.paymentterm.error')
                ->atPath('paymentDueDays')
                ->setTranslationDomain('messages')
                ->addViolation();
            $context->buildViolation('invoice.settings.paymentterm.error')
                ->atPath('paymentTerms')
                ->setTranslationDomain('messages')
                ->addViolation();
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvoiceSettingsData::class,
            'constraints' => [
                new Callback([$this, 'validatePaymentTerms']),
                new Callback([$this, 'validateProfileRequirements']),
            ],
        ]);
    }
}
