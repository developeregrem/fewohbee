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

use App\Entity\Customer;
use App\Entity\Template;
use App\Interfaces\ITemplatePreviewProvider;
use App\Service\CustomerService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Preview provider for GDPR export PDF templates.
 */
class GdprTemplatePreviewProvider implements ITemplatePreviewProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerService $customerService
    ) {
    }

    public function supportsPreview(Template $template): bool
    {
        return $template->getTemplateType()?->getName() === 'TEMPLATE_GDPR_PDF';
    }

    public function getPreviewContextDefinition(): array
    {
        return [
            [
                'name' => 'customerId',
                'type' => 'text',
                'label' => 'templates.preview.gdpr.customer_id',
                'placeholder' => 'templates.preview.gdpr.customer_id.placeholder',
                'help' => 'templates.preview.gdpr.customer_id.help',
            ],
        ];
    }

    public function buildSampleContext(): array
    {
        $customer = $this->em->getRepository(Customer::class)->findOneBy([], ['id' => 'DESC']);
        if ($customer instanceof Customer) {
            return ['customerId' => (string) $customer->getId()];
        }

        return [];
    }

    public function buildPreviewRenderParams(Template $template, array $ctx): array
    {
        $customerId = $ctx['customerId'] ?? null;
        if (is_string($customerId) && '' !== trim($customerId)) {
            $customerId = trim($customerId);
            if (is_numeric($customerId)) {
                $customer = $this->em->getRepository(Customer::class)->find((int) $customerId);
                if ($customer instanceof Customer) {
                    return $this->customerService->getRenderParams($template, $customer);
                }
            }

            $ctx['_previewWarning'] = 'templates.preview.gdpr.customer_notfound';
            $ctx['_previewWarningVars'] = ['%value%' => $customerId];
        }

        $latest = $this->em->getRepository(Customer::class)->findOneBy([], ['id' => 'DESC']);
        if ($latest instanceof Customer) {
            return $this->customerService->getRenderParams($template, $latest);
        }

        return $this->buildSampleParams($ctx);
    }

    public function getRenderParamsSchema(): array
    {
        return [
            'customer' => ['class' => Customer::class],
        ];
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'gdpr.general.name',
                'label' => 'templates.preview.snippet.gdpr.general_name',
                'group' => 'GDPR',
                'complexity' => 'simple',
                'content' => '[[ customer.salutation ]] [[ customer.firstname ]] [[ customer.lastname ]]',
            ],
            [
                'id' => 'gdpr.general.birthday',
                'label' => 'templates.preview.snippet.gdpr.general_birthday',
                'group' => 'GDPR',
                'complexity' => 'simple',
                'content' => "[[ customer.birthday ? customer.birthday|date('d.m.Y') : '' ]]",
            ],
            [
                'id' => 'gdpr.general.remark',
                'label' => 'templates.preview.snippet.gdpr.general_remark',
                'group' => 'GDPR',
                'complexity' => 'simple',
                'content' => '[[ customer.remarkF ]]',
            ],
            [
                'id' => 'gdpr.addresses.block',
                'label' => 'templates.preview.snippet.gdpr.addresses',
                'group' => 'GDPR',
                'complexity' => 'easy',
                'content' => "<div data-repeat=\"customer.customerAddresses\" data-repeat-as=\"address\"><h6 class=\"panel-title\">[[ 'customer.contact'|trans ]] ([[ address.type|trans ]])</h6><table class=\"general\"><tbody><tr data-if=\"address.company\"><td class=\"first\">[[ 'customer.company'|trans ]]:</td><td>[[ address.company ]]</td></tr><tr><td class=\"first\">[[ 'reservation.preview.customer.address'|trans ]]:</td><td>[[ address.address ]]<br />[[ address.zip ]] [[ address.city ]]<br />[[ address.country ]]</td></tr><tr><td class=\"first\">[[ 'customer.email'|trans ]]:</td><td>[[ address.email ? address.email : '-' ]]</td></tr><tr><td class=\"first\">[[ 'customer.phone'|trans ]]:</td><td>[[ address.phone ? address.phone : '-' ]]</td></tr><tr><td class=\"first\">[[ 'customer.fax'|trans ]]:</td><td>[[ address.fax ? address.fax : '-' ]]</td></tr><tr><td class=\"first\">[[ 'customer.mobilephone'|trans ]]:</td><td>[[ address.mobilephone ? address.mobilephone : '-' ]]</td></tr></tbody></table></div>",
            ],
            [
                'id' => 'gdpr.reservations.block',
                'label' => 'templates.preview.snippet.gdpr.reservations',
                'group' => 'GDPR',
                'complexity' => 'easy',
                'content' => "<div data-repeat=\"customer.reservations\" data-repeat-as=\"reservation\"><h6>[[ reservation.startDate|date('d.m.Y') ]] - [[ reservation.endDate|date('d.m.Y') ]] ([[ reservation.appartment.description ]])</h6><table class=\"general\"><tbody><tr><td class=\"first\">Gebucht über:</td><td>[[ reservation.reservationOrigin.name ]]</td></tr><tr><td class=\"first\">Anzahl Personen:</td><td>[[ reservation.persons ]]</td></tr><tr><td class=\"first\">Rechnungen:</td><td><span data-repeat=\"reservation.invoices\" data-repeat-as=\"invoice\">[[ invoice.number ]] ([[ invoice.date|date('d.m.Y') ]]), </span></td></tr></tbody></table></div>",
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
     * Build minimal in-memory sample payload when no customer exists yet.
     *
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    private function buildSampleParams(array $ctx = []): array
    {
        $customer = [
            'salutation' => 'Herr',
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'birthday' => new \DateTime('1985-03-19'),
            'remarkF' => 'Beispielkunde fuer die DSGVO-Vorschau',
            'customerAddresses' => [
                [
                    'type' => 'private',
                    'company' => '',
                    'address' => 'Musterweg 1',
                    'zip' => '12345',
                    'city' => 'Musterstadt',
                    'country' => 'DE',
                    'email' => 'max.mustermann@example.com',
                    'phone' => '+49 123 4567',
                    'fax' => '',
                    'mobilephone' => '+49 171 1234567',
                ],
            ],
            'reservations' => [
                [
                    'startDate' => new \DateTime('today'),
                    'endDate' => new \DateTime('+3 days'),
                    'persons' => 2,
                    'appartment' => ['description' => 'Appartement 101'],
                    'reservationOrigin' => ['name' => 'Direktbuchung'],
                    'invoices' => [
                        [
                            'number' => 'R-1001',
                            'date' => new \DateTime('today'),
                        ],
                    ],
                ],
            ],
        ];

        return array_merge([
            'customer' => $customer,
        ], $ctx);
    }
}

