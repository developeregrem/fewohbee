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

namespace App\DataFixtures;

use App\Entity\Template;
use App\Entity\TemplateType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\Translation\TranslatorInterface;

class TemplatesFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public static function getGroups(): array
    {
        return ['templates'];
    }

    public function load(ObjectManager $manager): void
    {
        $baseUrl = 'https://raw.githubusercontent.com/developeregrem/fewohbee-examples/master/templates/';
        $templates = [
            'TEMPLATE_GDPR_PDF' => [
                ['file' => 'dsgvo-export.txt', 'isDefault' => true],
            ],
            'TEMPLATE_CASHJOURNAL_PDF' => [
                ['file' => 'kassenblatt.txt', 'isDefault' => true],
            ],
            'TEMPLATE_RESERVATION_EMAIL' => [
                ['file' => 'email-buchungsbestätigung.txt', 'isDefault' => true,],
            ],
            'TEMPLATE_RESERVATION_PDF' => [
                ['file' => 'pdf-reservierungsbestätigung.txt', 'isDefault' => true,],
            ],
            'TEMPLATE_INVOICE_PDF' => [
                ['file' => 'rechnung-default.txt', 'isDefault' => true,],
            ],
            'TEMPLATE_OPERATIONS_PDF' => [
                [
                    'file' => 'report_housekeeping_day.html.twig',
                    'name' => 'templates.operations.housekeeping_day',
                    'isDefault' => true,
                    'params' => ['orientation' => 'L'],
                ],
                [
                    'file' => 'report_housekeeping_week.html.twig',
                    'name' => 'templates.operations.housekeeping_week',
                    'params' => ['orientation' => 'L'],
                ],
                [
                    'file' => 'report_frontdesk_checklist.html.twig',
                    'name' => 'templates.operations.frontdesk_checklist',
                ],
                [
                    'file' => 'report_meals_checklist.html.twig',
                    'name' => 'templates.operations.meals_checklist',
                ],
                [
                    'file' => 'report_management_monthly_summary.html.twig',
                    'name' => 'templates.operations.management_monthly_summary',
                ],
            ],
        ];
        $types = $manager->getRepository(TemplateType::class)->findAll();
        $client = HttpClient::create();
        /* @var $type TemplateType */
        foreach ($types as $type) {
            $name = $type->getName();
            if (!array_key_exists($name, $templates)) {
                continue;
            }

            $templateEntries = $templates[$name];
            foreach ($templateEntries as $entry) {
                $templateFile = $entry['file'];
                $response = $client->request('GET', $baseUrl.$templateFile);
                if (200 !== $response->getStatusCode()) {
                    echo "Could not load $templateFile";
                    continue;
                }

                $content = $response->getContent();
                $customParams = $entry['params'] ?? [];
                $template = new Template();
                $template->setParams($this->buildTemplateParams($customParams));
                $template->setIsDefault(isset($entry['isDefault']) ? (bool) $entry['isDefault'] : false);
                $template->setName($this->resolveTemplateName($name, $entry['name'] ?? null));
                $template->setTemplateType($type);
                $template->setText($content);

                $manager->persist($template);
            }
        }

        $manager->flush();
    }

    /**
     * Derive a display name for a template fixture.
     */
    private function resolveTemplateName(string $typeName, ?string $translationKey): string
    {
        if (null !== $translationKey) {
            return $this->translator->trans($translationKey);
        }

        return $this->translator->trans($typeName);
    }

    /**
     * Build template params by merging custom settings with defaults.
     */
    private function buildTemplateParams(array $custom): string
    {
        $params = array_merge([
            'orientation' => 'P',
            'marginLeft' => 25,
            'marginRight' => 20,
            'marginTop' => 20,
            'marginBottom' => 20,
            'marginHeader' => 9,
            'marginFooter' => 9,
        ], $custom);

        return json_encode($params, JSON_THROW_ON_ERROR);
    }
}
