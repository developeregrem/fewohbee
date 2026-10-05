<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSettings;
use App\Entity\Enum\PriceRounding;
use App\Entity\PriceRule;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PriceRuleControllerTest extends WebTestCase
{
    public function testAnAdministratorCreatesAWeekendRuleFromItsTemplate(): void
    {
        $client = $this->adminClient();

        $picker = $client->request('GET', '/settings/prices/rules/new');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $picker->filter('[data-url$="/new?template=weekend"]'));

        $crawler = $client->request('GET', '/settings/prices/rules/new?template=weekend');
        $form = $crawler->filter('form')->form();
        $form['price_rule[name]'] = 'Wochenende Test';
        $form['price_rule[amount]'] = '12';
        // Far ahead, so a test stopped half-way never changes the prices other tests see.
        $form['price_rule[firstNight]'] = (new \DateTimeImmutable('+3000 days'))->format('Y-m-d');
        $form['price_rule[lastNight]'] = (new \DateTimeImmutable('+3001 days'))->format('Y-m-d');

        // The preview judges the form as it is on screen, without saving anything.
        $client->request('POST', '/settings/prices/rules/preview', $form->getPhpValues());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('price_rules.preview', (string) $client->getResponse()->getContent());
        self::assertNull($this->findRule('Wochenende Test'));

        $client->submit($form);
        self::assertResponseStatusCodeSame(204);

        $rule = $this->findRule('Wochenende Test');
        self::assertInstanceOf(PriceRule::class, $rule);
        self::assertSame(12.0, $rule->getPercent());
        self::assertSame([5, 6], $rule->getWeekdays());
        self::assertTrue($rule->isAllSubsidiaries());

        $index = $client->request('GET', '/settings/prices/rules/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Wochenende Test', $index->filter(sprintf('#price-rule-%d', $rule->getId()))->text());

        // Requests run in their own kernel; remove the copy of the current entity manager.
        $this->em()->remove($this->findRule('Wochenende Test'));
        $this->em()->flush();
    }

    public function testAnIncompleteRuleIsSentBackWithItsErrors(): void
    {
        $client = $this->adminClient();
        $form = $client->request('GET', '/settings/prices/rules/new?template=last_minute')->filter('form')->form();
        $form['price_rule[name]'] = '';
        $form['price_rule[days]'] = '';

        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findRule(''));
    }

    public function testARuleIsSwitchedOffOnlyWithAValidToken(): void
    {
        $client = $this->adminClient();
        $rule = $this->createRule('Toggle Test');

        $client->request('POST', sprintf('/settings/prices/rules/%d/toggle', $rule->getId()), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/settings/prices/rules/');
        $token = $crawler->filter(sprintf('form[action$="/rules/%d/toggle"] input[name="_token"]', $rule->getId()))->attr('value');
        $client->request('POST', sprintf('/settings/prices/rules/%d/toggle', $rule->getId()), ['_token' => $token], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(204);
        self::assertFalse($this->findRule('Toggle Test')?->isEnabled());

        $popover = $crawler->filter(sprintf('[data-bs-content*="/settings/prices/rules/%d"]', $rule->getId()))->attr('data-bs-content');
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', (string) $popover, $match));
        $client->request('DELETE', sprintf('/settings/prices/rules/%d', $rule->getId()), ['_token' => $match[1]]);
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->findRule('Toggle Test'));
    }

    public function testTheLimitsAreSavedAsBoundsAroundZero(): void
    {
        $client = $this->adminClient();
        $form = $client->request('GET', '/settings/prices/rules/limits')->filter('form')->form();
        $form['price_change_limits[maxDecrease]'] = '20';
        $form['price_change_limits[maxIncrease]'] = '40';
        $form['price_change_limits[rounding]'] = PriceRounding::CENT->value;

        try {
            $client->submit($form);
            self::assertResponseStatusCodeSame(204);

            $this->em()->clear();
            $settings = $this->em()->getRepository(AppSettings::class)->findOneBy([]);
            self::assertSame(-20, $settings?->getPriceChangeMinPercent());
            self::assertSame(40, $settings->getPriceChangeMaxPercent());
            self::assertSame(PriceRounding::CENT, $settings->getPriceChangeRounding());
        } finally {
            $settings = $this->em()->getRepository(AppSettings::class)->findOneBy([]);
            $settings?->setPriceChangeLimits(-30, 50)->setPriceChangeRounding(PriceRounding::EURO);
            $this->em()->flush();
        }
    }

    public function testStaffWithoutAdministrationCannotSeeThePriceRules(): void
    {
        $client = self::createClient();
        $client->loginUser($this->reservationsUser(), 'main');

        $client->request('GET', '/settings/prices/rules/');

        self::assertResponseStatusCodeSame(403);
    }

    private function createRule(string $name): PriceRule
    {
        $rule = new PriceRule();
        $rule->setName($name);
        $rule->setPercent(10.0);
        // Far ahead and short, so other tests never price a night it covers.
        $rule->setPeriod(new \DateTimeImmutable('+3000 days'), new \DateTimeImmutable('+3001 days'));
        $this->em()->persist($rule);
        $this->em()->flush();

        return $rule;
    }

    private function findRule(string $name): ?PriceRule
    {
        $this->em()->clear();

        return $this->em()->getRepository(PriceRule::class)->findOneBy(['name' => $name]);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser($this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin'])
            ?? throw new \RuntimeException('Prepared test admin missing.'), 'main');

        return $client;
    }

    private function reservationsUser(): User
    {
        $em = $this->em();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'price-rules-staff']);
        if ($user instanceof User) {
            return $user;
        }
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS'])
            ?? throw new \RuntimeException('Role ROLE_RESERVATIONS missing.');
        $user = new User();
        $user->setUsername('price-rules-staff');
        $user->setFirstname('Price');
        $user->setLastname('Staff');
        $user->setEmail('price-rules-staff@example.com');
        $user->setPassword('unused');
        $user->setActive(true);
        $user->setRoleEntities([$role]);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
