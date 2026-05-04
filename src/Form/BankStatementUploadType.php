<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingAccount;
use App\Repository\AccountingAccountRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

class BankStatementUploadType extends AbstractType
{
    public function __construct(
        private readonly AccountingSettingsService $settingsService,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $activePreset = $this->settingsService->getActivePreset();

        $builder
            ->add('bankAccount', EntityType::class, [
                'class' => AccountingAccount::class,
                'label' => 'accounting.bank_import.upload.bank_account',
                'help' => 'accounting.bank_import.upload.bank_account.help',
                'placeholder' => 'accounting.bank_import.upload.bank_account.placeholder',
                'choice_label' => 'label',
                'query_builder' => fn (AccountingAccountRepository $repo) => $repo->createBankAccountsQueryBuilder($activePreset),
                'constraints' => [new NotNull()],
            ])
            ->add('format', BankImportFormatType::class, [
                'label' => 'accounting.bank_import.upload.format',
                'constraints' => [new NotNull()],
            ])
            ->add('file', FileType::class, [
                'label' => 'accounting.bank_import.upload.file',
                'mapped' => false,
                'multiple' => true,
                'constraints' => [
                    new All([
                        new File(
                            maxSize: '5M',
                            mimeTypes: [
                                'text/csv',
                                'text/plain',
                                'text/xml',
                                'application/csv',
                                'application/xml',
                                'application/vnd.ms-excel',
                            ],
                            mimeTypesMessage: 'accounting.bank_import.upload.file.invalid_type',
                        ),
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
