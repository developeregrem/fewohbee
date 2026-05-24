<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Text input that accepts both Excel-style column letters (A, B, …, AA)
 * and 0-based column indexes (0, 1, …). The model value is always the
 * 0-based integer index — the letter notation is only a display aid.
 */
final class CsvColumnType extends AbstractType
{
    public function getParent(): string
    {
        return TextType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static function (?int $index): string {
                if (null === $index || $index < 0) {
                    return '';
                }

                return self::indexToLetters($index);
            },
            static function (?string $input): ?int {
                if (null === $input) {
                    return null;
                }
                $value = strtoupper(trim($input));
                if ('' === $value) {
                    return null;
                }
                if (1 === preg_match('/^\d+$/', $value)) {
                    $n = (int) $value;
                    if ($n < 0 || $n > 1000) {
                        throw new TransformationFailedException('Out of range');
                    }

                    return $n;
                }
                if (1 === preg_match('/^[A-Z]+$/', $value)) {
                    return self::lettersToIndex($value);
                }
                throw new TransformationFailedException('Invalid column reference');
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'invalid_message' => 'accounting.bank_import.profile.col.invalid',
        ]);
    }

    public static function indexToLetters(int $index): string
    {
        $result = '';
        $n = $index;
        while (true) {
            $result = chr(65 + ($n % 26)).$result;
            $n = intdiv($n, 26) - 1;
            if ($n < 0) {
                break;
            }
        }

        return $result;
    }

    public static function lettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; ++$i) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
