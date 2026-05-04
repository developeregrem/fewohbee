<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Form\Type\CsvColumnType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvColumnTypeTest extends TestCase
{
    #[DataProvider('indexLetterPairs')]
    public function testIndexToLetters(int $index, string $letters): void
    {
        self::assertSame($letters, CsvColumnType::indexToLetters($index));
    }

    #[DataProvider('indexLetterPairs')]
    public function testLettersToIndex(int $index, string $letters): void
    {
        self::assertSame($index, CsvColumnType::lettersToIndex($letters));
    }

    public static function indexLetterPairs(): iterable
    {
        yield [0, 'A'];
        yield [1, 'B'];
        yield [25, 'Z'];
        yield [26, 'AA'];
        yield [27, 'AB'];
        yield [51, 'AZ'];
        yield [52, 'BA'];
        yield [701, 'ZZ'];
        yield [702, 'AAA'];
    }

    public function testLettersToIndexIsCaseInsensitive(): void
    {
        self::assertSame(0, CsvColumnType::lettersToIndex('a'));
        self::assertSame(26, CsvColumnType::lettersToIndex('aa'));
    }
}
