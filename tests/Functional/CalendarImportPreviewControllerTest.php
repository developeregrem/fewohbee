<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CalendarSync;
use App\Entity\User;
use App\Service\Calendar\Sync\CalendarImportPreviewService;
use App\Service\Calendar\Sync\CalendarImportSummaryMatcher;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use App\Service\Calendar\Sync\Ics\IcsOccurrenceReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Verify authorization, CSRF protection and JSON output of the calendar-import preview. */
final class CalendarImportPreviewControllerTest extends WebTestCase
{
    public function testPreviewRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('POST', '/settings/apartments/sync/import/preview');

        self::assertResponseRedirects();
    }

    public function testPreviewRejectsInvalidCsrfToken(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $client->request('POST', '/settings/apartments/sync/import/preview', [
            '_token' => 'invalid',
            'url' => 'https://example.test/calendar.ics',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPreviewReturnsGroupedCurrentEntries(): void
    {
        $client = self::createClient();
        $client->loginUser($this->getAdminUser(), 'main');
        $sync = $this->getCalendarSync();

        $crawler = $client->request('GET', '/settings/apartments/sync/'.$sync->getId().'/edit');
        self::assertResponseIsSuccessful();
        $tokenNode = $crawler->filter('[data-calendar-imports-preview-token-value]');
        self::assertCount(1, $tokenNode);
        $token = (string) $tokenNode->attr('data-calendar-imports-preview-token-value');

        self::getContainer()->set(CalendarImportPreviewService::class, new CalendarImportPreviewService(
            new IcsFeedClient(new MockHttpClient(new MockResponse(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:preview-controller
DTSTART;VALUE=DATE:20990110
DTEND;VALUE=DATE:20990112
SUMMARY:Reserved
END:VEVENT
END:VCALENDAR
ICS))),
            new IcsOccurrenceReader(),
            new CalendarImportSummaryMatcher(),
        ));

        $client->request('POST', '/settings/apartments/sync/import/preview', [
            '_token' => $token,
            'url' => 'https://example.test/calendar.ics',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['success'] ?? false);
        self::assertSame(1, $payload['eventCount'] ?? null);
        self::assertSame('Reserved', $payload['groups'][0]['summary'] ?? null);
        self::assertNull($payload['sharedFilters'] ?? null);
    }

    /** Return a persisted calendar sync used to render a genuine preview CSRF token. */
    private function getCalendarSync(): CalendarSync
    {
        $sync = $this->getEntityManager()->getRepository(CalendarSync::class)->findOneBy([]);
        if (!$sync instanceof CalendarSync) {
            self::fail('Calendar sync not found in test fixtures.');
        }

        return $sync;
    }

    /** Return the seeded administrator required by the settings controller. */
    private function getAdminUser(): User
    {
        $user = $this->getEntityManager()->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        if (!$user instanceof User) {
            self::fail('Admin user not found in test fixtures.');
        }

        return $user;
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine')->getManager();
    }
}
