<?php

declare(strict_types=1);

namespace App\Service\JournalExport;

use App\Entity\AccountingSettings;
use App\Entity\BookingBatch;

interface BookingExportInterface
{
    public function getFormatName(): string;

    public function getFileExtension(): string;

    public function getMimeType(): string;

    /**
     * Export a batch to a string in the target format.
     */
    public function export(BookingBatch $batch, AccountingSettings $settings, string $currency = 'EUR'): string;
}
