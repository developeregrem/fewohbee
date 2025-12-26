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
            'TEMPLATE_GDPR_PDF' => 'dsgvo-export.txt',
            'TEMPLATE_CASHJOURNAL_PDF' => 'kassenblatt.txt',
            'TEMPLATE_RESERVATION_EMAIL' => 'email-buchungsbestätigung.txt',
            'TEMPLATE_RESERVATION_PDF' => 'pdf-reservierungsbestätigung.txt',
            'TEMPLATE_INVOICE_PDF' => 'rechnung-default.txt',
        ];
        $types = $manager->getRepository(TemplateType::class)->findAll();
        $client = HttpClient::create();
        /* @var $type TemplateType */
        foreach ($types as $type) {
            $name = $type->getName();
            if (!array_key_exists($name, $templates)) {
                continue;
            }

            $response = $client->request('GET', $baseUrl.$templates[$name]);
            if (200 !== $response->getStatusCode()) {
                echo "Could not load $name";
                continue;
            }

            $content = $response->getContent();

            $template = new Template();
            $template->setParams('{"orientation": "P", "marginLeft": 25, "marginRight": 20, "marginTop": 20, "marginBottom": 20, "marginHeader": 9, "marginFooter": 9}');
            $template->setIsDefault(true);
            $template->setName($this->translator->trans($name));
            $template->setTemplateType($type);
            $template->setText($content);

            $manager->persist($template);
        }

        $manager->flush();
    }
}
