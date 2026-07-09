<?php

declare(strict_types=1);

namespace App\Tests\Unit\EInvoice;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\AppSettingsService;
use App\Service\EInvoice\Validation\EInvoiceFixLocation;
use App\Service\EInvoice\Validation\EInvoiceValidationException;
use App\Service\EInvoice\Validation\EInvoiceValidationResult;
use App\Service\EInvoice\Validation\EInvoiceValidatorInterface;
use App\Service\EInvoice\Validation\EInvoiceViolation;
use App\Service\EInvoice\ZugferdInvoiceGenerator;
use horstoeko\zugferd\ZugferdProfiles;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ZugferdInvoiceGeneratorValidationTest extends TestCase
{
    public function testGeneratorThrowsValidationExceptionWithResult(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $appSettings = $this->createStub(AppSettingsService::class);

        $generator = new ZugferdInvoiceGenerator($translator, $appSettings);

        $violation = new EInvoiceViolation('buyerCountry', 'invoice.einvoice.violation.buyerCountry', EInvoiceFixLocation::INVOICE);
        $validator = $this->createStub(EInvoiceValidatorInterface::class);
        $validator->method('validate')->willReturn(new EInvoiceValidationResult([$violation]));

        try {
            $generator->generateInvoiceData(new Invoice(), new InvoiceSettingsData(), ZugferdProfiles::PROFILE_EN16931, $validator);
            self::fail('Expected EInvoiceValidationException');
        } catch (EInvoiceValidationException $e) {
            self::assertFalse($e->result->isValid());
            self::assertSame(['invoice.einvoice.violation.buyerCountry'], $e->result->getMessageKeys());
        }
    }
}
