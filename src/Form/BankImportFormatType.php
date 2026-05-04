<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\BookingJournal\BankImport\BankImportFormatChoice;
use App\Entity\BankCsvProfile;
use App\Repository\BankCsvProfileRepository;
use App\Service\BookingJournal\BankImport\Parser\GenericCsvParser;
use App\Service\BookingJournal\BankImport\Parser\Iso20022CamtParser;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Format selector for bank statement uploads.
 *
 * The view value is a flat string ("iso20022_camt" or "csv:<profileId>") so
 * it round-trips through HTML form posts. A model transformer resolves that
 * to a {@see BankImportFormatChoice} value object — the controller therefore
 * receives the parser format key plus the loaded CSV profile and never has
 * to inspect the raw selection string.
 */
final class BankImportFormatType extends AbstractType
{
    public function __construct(
        private readonly BankCsvProfileRepository $csvProfileRepository,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            fn (?BankImportFormatChoice $choice): ?string => null === $choice ? null : $this->encode($choice),
            fn (?string $selection): ?BankImportFormatChoice => null === $selection || '' === $selection
                ? null
                : $this->decode($selection),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => $this->buildChoices(),
            'choice_translation_domain' => false,
            'placeholder' => 'accounting.bank_import.upload.format.placeholder',
            'expanded' => false,
            'multiple' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildChoices(): array
    {
        $choices = [
            $this->trans('accounting.bank_import.upload.format.camt') => Iso20022CamtParser::FORMAT_KEY,
        ];

        foreach ($this->csvProfileRepository->findAllOrdered() as $profile) {
            if (null === $profile->getId()) {
                continue;
            }
            $choices[$this->trans('accounting.bank_import.upload.format.csv_profile', [
                '%profile%' => $profile->getName(),
            ])] = 'csv:'.$profile->getId();
        }

        return $choices;
    }

    private function decode(string $selection): BankImportFormatChoice
    {
        if (Iso20022CamtParser::FORMAT_KEY === $selection) {
            return new BankImportFormatChoice(Iso20022CamtParser::FORMAT_KEY, null);
        }

        if (str_starts_with($selection, 'csv:')) {
            $profileId = (int) substr($selection, 4);
            $profile = $this->csvProfileRepository->find($profileId);
            if (!$profile instanceof BankCsvProfile) {
                throw new \InvalidArgumentException($this->trans('accounting.bank_import.upload.flash.csv_profile_missing'));
            }

            return new BankImportFormatChoice(GenericCsvParser::FORMAT_KEY, $profile);
        }

        throw new \InvalidArgumentException($this->trans('accounting.bank_import.upload.flash.invalid'));
    }

    private function encode(BankImportFormatChoice $choice): string
    {
        if (Iso20022CamtParser::FORMAT_KEY === $choice->formatKey) {
            return Iso20022CamtParser::FORMAT_KEY;
        }

        return 'csv:'.($choice->profile?->getId() ?? 0);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator?->trans($key, $parameters) ?? $key;
    }
}
