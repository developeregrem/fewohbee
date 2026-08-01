<?php

declare(strict_types=1);

namespace App\Service\EInvoice\Validation;

// Collected result of an e-invoice pre-generation validation.
final class EInvoiceValidationResult
{
    /**
     * @param EInvoiceViolation[] $violations
     */
    public function __construct(private array $violations = [])
    {
    }

    public function isValid(): bool
    {
        return [] === $this->violations;
    }

    /**
     * @return EInvoiceViolation[]
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * @return EInvoiceViolation[]
     */
    public function getViolationsByLocation(EInvoiceFixLocation $location): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn (EInvoiceViolation $violation): bool => $violation->fixLocation === $location
        ));
    }

    /**
     * @return string[] translation keys of all violations
     */
    public function getMessageKeys(): array
    {
        return array_map(static fn (EInvoiceViolation $violation): string => $violation->messageKey, $this->violations);
    }
}
