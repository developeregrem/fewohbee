<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Template;
use App\Entity\TemplateType;
use App\Service\TemplatePreview\TemplatePreviewProviderRegistry;
use App\Service\TemplatesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Twig\Sandbox\SecurityError;

/** Verify stored templates and the real container wiring against the template sandbox. */
final class TemplateSandboxRenderingTest extends KernelTestCase
{
    public function testStoredTemplatesRenderTheirPreviewInsideTheSandbox(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(TemplatesService::class);
        $registry = self::getContainer()->get(TemplatePreviewProviderRegistry::class);
        $rendered = 0;
        $violations = [];

        foreach ($this->em()->getRepository(Template::class)->findAll() as $template) {
            $provider = $registry->getProvider($template);
            if (null === $provider) {
                continue;
            }

            $label = $template->getTemplateType()?->getName().' "'.$template->getName().'"';
            try {
                $params = $service->inTemplateLanguage($template, static fn (): array => $provider->buildPreviewRenderParams($template, $provider->buildSampleContext()));
                $service->inTemplateLanguage($template, static fn (): string => $service->renderTemplateString(
                    (string) $template->getText(),
                    $params,
                    $template->getTemplateType()?->getName(),
                    $template->getLocale(),
                ));
                if ('' !== trim((string) $template->getSubject())) {
                    $service->inTemplateLanguage($template, static fn (): string => $service->renderSubjectString((string) $template->getSubject(), $params));
                }
                ++$rendered;
            } catch (\Throwable $e) {
                // Missing sample data is not the subject here; only sandbox rejections are.
                if (null !== $this->securityError($e)) {
                    $violations[] = $label.': '.$e->getMessage();
                }
            }
        }

        self::assertSame([], $violations);
        // The prepared sample data holds the example templates; most of them must really render.
        self::assertGreaterThanOrEqual(10, $rendered);
    }

    public function testPhpFunctionsAreNotReachableFromTemplateText(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(TemplatesService::class);

        foreach (["[[ ['probe']|map('strtoupper')|join ]]", "{{ ['probe']|filter('strtoupper')|join }}"] as $source) {
            try {
                $output = $service->renderTemplateString($source, []);
                self::fail('A PHP function must not be callable, got: '.$output);
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString('PROBE', $e->getMessage());
                self::assertStringContainsString('Closure', $e->getMessage());
            }
        }
    }

    public function testApplicationFunctionsOutsideTheAllowListAreRejected(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(TemplatesService::class);

        try {
            $service->renderTemplateString("[[ existsById('App\\\\Entity\\\\User', 1) ]]", []);
            self::fail('Functions of the application environment must not be usable in templates.');
        } catch (\RuntimeException $e) {
            self::assertNotNull($this->securityError($e));
            self::assertStringContainsString('"existsById"', $e->getMessage());
        }
    }

    public function testCheckCommandListsTemplatesThatUseDisallowedFeatures(): void
    {
        self::bootKernel();
        $tester = new CommandTester(new Application(self::$kernel)->find('app:templates:check'));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $type = $this->em()->getRepository(TemplateType::class)->findOneBy(['name' => 'TEMPLATE_RESERVATION_EMAIL']);
        self::assertInstanceOf(TemplateType::class, $type);
        $template = new Template();
        $template->setTemplateType($type);
        $template->setName('Sandbox probe '.uniqid());
        $template->setText("<p>[[ source('base.html.twig') ]]</p>");
        $template->setParams('{}');
        $this->em()->persist($template);
        $this->em()->flush();

        try {
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString($template->getName(), $tester->getDisplay());
        } finally {
            $this->em()->remove($template);
            $this->em()->flush();
        }
    }

    private function securityError(\Throwable $e): ?SecurityError
    {
        for ($cause = $e; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof SecurityError) {
                return $cause;
            }
        }

        return null;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
