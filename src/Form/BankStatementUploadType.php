<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingAccount;
use App\Entity\BankCsvProfile;
use App\Repository\AccountingAccountRepository;
use App\Repository\BankCsvProfileRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
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
            ->add('csvProfile', EntityType::class, [
                'class' => BankCsvProfile::class,
                'label' => 'accounting.bank_import.upload.csv_profile',
                'placeholder' => 'accounting.bank_import.upload.csv_profile.placeholder',
                'choice_label' => 'name',
                'query_builder' => static fn (BankCsvProfileRepository $repo) => $repo->createOrderedQueryBuilder(),
                'constraints' => [new NotNull()],
            ])
            ->add('file', FileType::class, [
                'label' => 'accounting.bank_import.upload.file',
                'mapped' => false,
                'constraints' => [
                    new File(
                        maxSize: '5M',
                        mimeTypes: [
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                        ],
                        mimeTypesMessage: 'accounting.bank_import.upload.file.invalid_type',
                    ),
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
