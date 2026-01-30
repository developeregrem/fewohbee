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

use App\Entity\TemplateType;
use App\Service\TemplatesService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class TemplatesFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(private readonly TemplatesService $templatesService)
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
            'TEMPLATE_OPERATIONS_PDF' => $this->templatesService->getOperationsTemplateDefinitions(),
        ];
        $types = $manager->getRepository(TemplateType::class)->findAll();
        /* @var $type TemplateType */
        foreach ($types as $type) {
            $name = $type->getName();
            if (!array_key_exists($name, $templates)) {
                continue;
            }

            $templateEntries = $templates[$name];
            $this->templatesService->importTemplates($type, $templateEntries, $baseUrl);
        }

        $manager->flush();
    }
}
