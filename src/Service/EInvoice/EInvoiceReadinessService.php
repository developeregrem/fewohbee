<?php

declare(strict_types=1);

namespace App\Service\EInvoice;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Repository\InvoiceSettingsDataRepository;
use Doctrine\ORM\EntityManagerInterface;

// Answers "can an e-invoice be generated for this invoice?" without generating anything,
// and is the single place that decides which issuer (company) data an invoice belongs to.
class EInvoiceReadinessService
{
    /**
     * Issuer per subsidiary id, memoized for the lifetime of the request. Key 0 stands for
     * "no subsidiary" and holds the globally active row. Without this, rendering an invoice
     * list would run one issuer lookup per invoice.
     *
     * @var array<int, InvoiceSettingsData|null>
     */
    private array $settingsBySubsidiary = [];

    public function __construct(
        private EntityManagerInterface $em,
        private EInvoiceExportService $exportService,
    ) {
    }

    /**
     * The globally active issuer — the fallback for invoices without a branch and for
     * branches that have no issuer of their own.
     */
    public function getActiveSettings(): ?InvoiceSettingsData
    {
        return $this->settingsRepository()->findActive();
    }

    /**
     * Issuer data for this invoice: the row assigned to its branch when there is one,
     * otherwise the globally active row. Null when nothing is configured at all.
     */
    public function resolveSettingsFor(Invoice $invoice): ?InvoiceSettingsData
    {
        $subsidiary = $invoice->getSubsidiary();
        $cacheKey = (int) ($subsidiary?->getId() ?? 0);

        if (\array_key_exists($cacheKey, $this->settingsBySubsidiary)) {
            return $this->settingsBySubsidiary[$cacheKey];
        }

        $settings = null;
        if (null !== $subsidiary) {
            $settings = $this->settingsRepository()->findForSubsidiary($subsidiary);
        }
        $settings ??= $this->getActiveSettings();

        return $this->settingsBySubsidiary[$cacheKey] = $settings;
    }

    // Profile key of the active settings, or null when e-invoicing is not configured.
    public function getActiveProfileKey(): ?string
    {
        $settings = $this->getActiveSettings();

        return $settings instanceof InvoiceSettingsData ? $this->exportService->resolveProfileKey($settings) : null;
    }

    public function check(Invoice $invoice, ?InvoiceSettingsData $settings = null): EInvoiceReadiness
    {
        $settings ??= $this->resolveSettingsFor($invoice);
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
        $readiness = [];
        foreach ($invoices as $invoice) {
            // Resolved per invoice because the issuer can now differ per branch; the
            // memoization above keeps this at one query per branch, not per invoice.
            $readiness[$invoice->getId()] = $this->check($invoice);
        }

        return $readiness;
    }

    private function settingsRepository(): InvoiceSettingsDataRepository
    {
        /** @var InvoiceSettingsDataRepository $repository */
        $repository = $this->em->getRepository(InvoiceSettingsData::class);

        return $repository;
    }
}
