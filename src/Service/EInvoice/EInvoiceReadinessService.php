<?php

declare(strict_types=1);

namespace App\Service\EInvoice;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use Doctrine\ORM\EntityManagerInterface;

// Answers "can an e-invoice be generated for this invoice?" without generating anything.
class EInvoiceReadinessService
{
    public function __construct(private EntityManagerInterface $em, private EInvoiceExportService $exportService)
    {
    }

    public function getActiveSettings(): ?InvoiceSettingsData
    {
        return $this->em->getRepository(InvoiceSettingsData::class)->findOneBy(['isActive' => true]);
    }

    // Profile key of the active settings, or null when e-invoicing is not configured.
    public function getActiveProfileKey(): ?string
    {
        $settings = $this->getActiveSettings();

        return $settings instanceof InvoiceSettingsData ? $this->exportService->resolveProfileKey($settings) : null;
    }

    public function check(Invoice $invoice, ?InvoiceSettingsData $settings = null): EInvoiceReadiness
    {
        $settings ??= $this->getActiveSettings();
        if (!($settings instanceof InvoiceSettingsData)) {
            return new EInvoiceReadiness(false, false, null, null);
        }

        $result = $this->exportService->validateInvoice($invoice, $settings);

        return new EInvoiceReadiness(true, $result->isValid(), $this->exportService->resolveProfileKey($settings), $result);
    }

    /**
     * @param iterable<Invoice> $invoices
     *
     * @return array<int, EInvoiceReadiness> keyed by invoice id
     */
    public function checkAll(iterable $invoices): array
    {
        $settings = $this->getActiveSettings();
        $readiness = [];
        foreach ($invoices as $invoice) {
            $readiness[$invoice->getId()] = $this->check($invoice, $settings);
        }

        return $readiness;
    }
}
