<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\TemplatePreview;

use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Template;
use App\Interfaces\ITemplatePreviewProvider;
use App\Service\InvoiceService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Preview provider for invoice PDF templates.
 */
class InvoiceTemplatePreviewProvider implements ITemplatePreviewProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceService $invoiceService
    ) {
    }

    public function supportsPreview(Template $template): bool
    {
        return $template->getTemplateType()?->getName() === 'TEMPLATE_INVOICE_PDF';
    }

    public function getPreviewContextDefinition(): array
    {
        return [
            [
                'name' => 'invoiceNumber',
                'type' => 'text',
                'label' => 'templates.preview.invoice.number',
                'placeholder' => 'templates.preview.invoice.number.placeholder',
                'help' => 'templates.preview.invoice.number.help',
            ],
        ];
    }

    public function buildSampleContext(): array
    {
        return [];
    }

    public function buildPreviewRenderParams(Template $template, array $ctx): array
    {
        $invoiceNumber = $ctx['invoiceNumber'] ?? null;
        if (is_string($invoiceNumber) && '' !== trim($invoiceNumber)) {
            $invoiceNumber = trim($invoiceNumber);
            $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['number' => $invoiceNumber]);
            if (!$invoice instanceof Invoice && is_numeric($invoiceNumber)) {
                $invoice = $this->em->getRepository(Invoice::class)->find((int) $invoiceNumber);
            }
            if ($invoice instanceof Invoice) {
                return $this->invoiceService->getRenderParams($template, $invoice->getId());
            }
            $ctx['_previewWarning'] = 'templates.preview.invoice.notfound';
            $ctx['_previewWarningVars'] = ['%value%' => $invoiceNumber];
        }

        return $this->buildSampleParams($ctx);
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'invoice.number',
                'label' => 'templates.preview.snippet.invoice_number',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ invoice.number ]]',
            ],
            [
                'id' => 'invoice.date',
                'label' => 'templates.preview.snippet.invoice_date',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => "[[ invoice.date|date('d.m.Y') ]]",
            ],
            [
                'id' => 'invoice.totals',
                'label' => 'templates.preview.snippet.invoice_totals',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ bruttoFormated ]]',
            ],
            [
                'id' => 'invoice.positions',
                'label' => 'templates.preview.snippet.invoice_positions',
                'group' => 'Invoice',
                'complexity' => 'advanced',
                'content' => "[% for position in invoice.positions %]\n<p>[[ position.description ]]</p>\n[% endfor %]",
            ],
            [
                'id' => 'pdf.header',
                'label' => 'templates.preview.snippet.pdf_header',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="header">\n  <p>Header</p>\n</div>',
            ],
            [
                'id' => 'pdf.footer',
                'label' => 'templates.preview.snippet.pdf_footer',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="footer">\n  <p>Footer</p>\n</div>',
            ],
        ];
    }

    /**
     * Build a minimal sample payload for invoice previews.
     */
    private function buildSampleParams(array $ctx = []): array
    {
        $invoice = new Invoice();
        $invoice->setNumber('2026-0001');
        $invoice->setDate(new \DateTime('today'));
        $invoice->setSalutation('Herr');
        $invoice->setFirstname('Max');
        $invoice->setLastname('Mustermann');
        $invoice->setCompany('Muster GmbH');
        $invoice->setAddress('Musterstraße 1');
        $invoice->setZip('12345');
        $invoice->setCity('Musterstadt');
        $invoice->setRemark('Vielen Dank für Ihren Aufenthalt.');

        $appartment = new InvoiceAppartment();
        $appartment->setNumber('1');
        $appartment->setDescription('Doppelzimmer');
        $appartment->setBeds(2);
        $appartment->setPersons(2);
        $appartment->setStartDate(new \DateTime('today'));
        $appartment->setEndDate(new \DateTime('today +3 days'));
        $appartment->setPrice(120.0);
        $appartment->setVat(7.0);
        $invoice->addAppartment($appartment);

        $position = new InvoicePosition();
        $position->setDescription('Frühstück');
        $position->setAmount(2);
        $position->setPrice(12.5);
        $position->setVat(7.0);
        $invoice->addPosition($position);

        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $appartmentTotal = 0.0;
        $miscTotal = 0.0;
        $this->invoiceService->calculateSums(
            new ArrayCollection($invoice->getAppartments()->toArray()),
            new ArrayCollection($invoice->getPositions()->toArray()),
            $vats,
            $brutto,
            $netto,
            $appartmentTotal,
            $miscTotal
        );

        $periods = $this->invoiceService->getUniqueReservationPeriods($invoice);
        $numbers = $this->invoiceService->getUniqueAppartmentsNumber($invoice);

        $params = [
            'invoice' => $invoice,
            'vats' => $vats,
            'brutto' => $brutto,
            'netto' => $netto,
            'bruttoFormated' => number_format($brutto, 2, ',', '.'),
            'nettoFormated' => number_format($brutto - $netto, 2, ',', '.'),
            'periods' => $periods,
            'numbers' => $numbers,
            'appartmentTotal' => number_format($appartmentTotal, 2, ',', '.'),
            'miscTotal' => number_format($miscTotal, 2, ',', '.'),
        ];

        return $this->appendPreviewMeta($params, $ctx);
    }

    /**
     * Append preview metadata such as warnings to the template params.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    private function appendPreviewMeta(array $params, array $ctx): array
    {
        if (!empty($ctx['_previewWarning'])) {
            $params['_previewWarning'] = $ctx['_previewWarning'];
            $params['_previewWarningVars'] = $ctx['_previewWarningVars'] ?? [];
        }

        return $params;
    }
}
