<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Service\BookingJournal\BankImport\UserRegexCompiler;
use PHPUnit\Framework\TestCase;

final class UserRegexCompilerTest extends TestCase
{
    private UserRegexCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new UserRegexCompiler();
    }

    public function testRawPatternGetsDelimitersAndFlags(): void
    {
        self::assertSame('/Rechnung\s+(\d+)/iu', $this->compiler->compile('Rechnung\s+(\d+)'));
    }

    public function testSlashesInsideRawPatternAreEscaped(): void
    {
        self::assertSame('/RE\/2026/iu', $this->compiler->compile('RE/2026'));
    }

    public function testPatternWithOwnDelimitersIsKept(): void
    {
        self::assertSame('#Zinsen#i', $this->compiler->compile('#Zinsen#i'));
    }

    public function testEmptyPatternCompilesToNull(): void
    {
        self::assertNull($this->compiler->compile(''));
    }

    public function testUsablePatternIsValid(): void
    {
        self::assertTrue($this->compiler->isValid('Rechnung\s+(\d+)'));
    }

    public function testUnbalancedGroupIsRejected(): void
    {
        self::assertFalse($this->compiler->isValid('Rechnung ('));
    }

    public function testEmptyPatternIsRejected(): void
    {
        self::assertFalse($this->compiler->isValid(''));
    }
}
