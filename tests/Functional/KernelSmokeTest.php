<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelSmokeTest extends KernelTestCase
{
    public function testKernelBootsSuccessfully(): void
    {
        self::bootKernel();

        self::assertSame('test', self::$kernel->getEnvironment());
        self::assertTrue(self::$kernel->getContainer()->has('doctrine'), 'Doctrine service should be available in the container.');
    }
}
