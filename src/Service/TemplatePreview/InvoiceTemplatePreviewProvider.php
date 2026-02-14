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
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy([], ['id' => 'DESC']);
        if ($invoice instanceof Invoice) {
            return ['invoiceNumber' => (string) $invoice->getNumber()];
        }

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

    public function getRenderParamsSchema(): array
    {
        return [
            'invoice' => ['class' => Invoice::class],
            'vats' => ['type' => 'array'],
            'brutto' => ['type' => 'scalar'],
            'netto' => ['type' => 'scalar'],
            'bruttoFormated' => ['type' => 'scalar'],
            'nettoFormated' => ['type' => 'scalar'],
            'periods' => ['type' => 'array'],
            'numbers' => ['type' => 'array'],
            'appartmentTotal' => ['type' => 'scalar'],
            'miscTotal' => ['type' => 'scalar'],
        ];
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'invoice.number',
                'label' => 'templates.editor.invoice.number',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ invoice.number ]]',
            ],
            [
                'id' => 'invoice.date',
                'label' => 'templates.editor.invoice.date',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => "[[ invoice.date|date('d.m.Y') ]]",
            ],
            [
                'id' => 'invoice.firstname',
                'label' => 'templates.editor.firstname',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ invoice.firstname ]]',
            ],
            [
                'id' => 'invoice.lastname',
                'label' => 'templates.editor.lastname',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ invoice.lastname ]]',
            ],
            [
                'id' => 'invoice.company',
                'label' => 'templates.editor.company',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ invoice.company ]]',
            ],
            [
                'id' => 'invoice.address',
                'label' => 'templates.editor.address',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ invoice.address ]]',
            ],
            [
                'id' => 'invoice.zip',
                'label' => 'templates.editor.zip',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ invoice.zip ]]',
            ],
            [
                'id' => 'invoice.city',
                'label' => 'templates.editor.city',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ invoice.city ]]',
            ],
            [
                'id' => 'invoice.remarks',
                'label' => 'templates.editor.remarks',
                'group' => 'Invoice',
                'complexity' => 'simple',
                'content' => '[[ invoice.remarkF ]]',
            ],
            [
                'id' => 'invoice.total.appartment',
                'label' => 'templates.editor.price.total',
                'group' => 'Totals',
                'complexity' => 'simple',
                'content' => '[[ appartmentTotal ]]',
            ],
            [
                'id' => 'invoice.total.misc',
                'label' => 'templates.editor.misc.total',
                'group' => 'Totals',
                'complexity' => 'simple',
                'content' => '[[ miscTotal ]]',
            ],
            [
                'id' => 'invoice.total.netto',
                'label' => 'templates.editor.netto',
                'group' => 'Totals',
                'complexity' => 'simple',
                'content' => '[[ nettoFormated ]]',
            ],
            [
                'id' => 'invoice.total.brutto',
                'label' => 'templates.editor.brutto',
                'group' => 'Totals',
                'complexity' => 'simple',
                'content' => '[[ bruttoFormated ]]',
            ],
            [
                'id' => 'invoice.vat',
                'label' => 'templates.editor.vat',
                'group' => 'Invoice',
                'complexity' => 'easy',
                'content' => "<table border=\"0\">\n  <tbody>\n    <tr data-repeat=\"vats\" data-repeat-key=\"key\" data-repeat-as=\"value\">\n      <td style=\"text-align: right;\">[[ key ]] %</td>\n      <td style=\"text-align: right;\">[[ value.nettoFormated ]] €</td>\n    </tr>\n  </tbody>\n</table>",
            ],
            [
                'id' => 'invoice.appartment.positions',
                'label' => 'templates.editor.appartment.positions',
                'group' => 'Invoice',
                'complexity' => 'easy',
                'content' => "<table style=\"width: 100%;\">\n  <tbody>\n    <tr>\n      <th>{{ 'invoice.position.appartment'|trans }}</th>\n      <th>{{ 'invoice.position.stays'|trans }}</th>\n      <th>{{ 'invoice.price.single'|trans }}</th>\n      <th>{{ 'invoice.vat'|trans }}</th>\n      <th style=\"text-align: right;\">{{ 'invoice.price.total'|trans }}</th>\n    </tr>\n    <tr data-repeat=\"invoice.appartments\" data-repeat-as=\"appartment\">\n      <td>[[ appartment.description ]] (Personen: [[ appartment.persons ]])<br />[[ appartment.startDate|date('d.m.Y') ]] - [[ appartment.endDate|date('d.m.Y') ]]</td>\n      <td>[[ appartment.amount ]]</td>\n      <td>[[ appartment.priceFormated ]] €</td>\n      <td>[[ appartment.vat ]]</td>\n      <td style=\"text-align: right;\">[[ appartment.totalPrice ]] €</td>\n    </tr>\n    <tr>\n      <td colspan=\"5\" style=\"text-align: right;\">[[ appartmentTotal ]] €</td>\n    </tr>\n  </tbody>\n</table>",
            ],
            [
                'id' => 'invoice.misc.positions',
                'label' => 'templates.editor.misc.positions',
                'group' => 'Invoice',
                'complexity' => 'easy',
                'content' => "<table style=\"width: 100%;\">\n  <tbody>\n    <tr>\n      <th>{{ 'invoice.position.additional'|trans }}</th>\n      <th>{{ 'invoice.position.amount'|trans }}</th>\n      <th>{{ 'invoice.price.single'|trans }}</th>\n      <th>{{ 'invoice.vat'|trans }}</th>\n      <th style=\"text-align: right;\">{{ 'invoice.price.total'|trans }}</th>\n    </tr>\n    <tr data-repeat=\"invoice.positions\" data-repeat-as=\"position\">\n      <td>[[ position.description ]]</td>\n      <td>[[ position.amount ]]</td>\n      <td>[[ position.priceFormated ]] €</td>\n      <td>[[ position.vat ]]</td>\n      <td style=\"text-align: right;\">[[ position.totalPrice ]] €</td>\n    </tr>\n    <tr>\n      <td colspan=\"5\" style=\"text-align: right;\">[[ miscTotal ]] €</td>\n    </tr>\n  </tbody>\n</table>",
            ],
            [
                'id' => 'pdf.header',
                'label' => 'templates.preview.snippet.pdf_header',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="header"><p>Header</p></div>',
            ],
            [
                'id' => 'pdf.footer',
                'label' => 'templates.preview.snippet.pdf_footer',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="footer"><p>Footer</p></div>',
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
