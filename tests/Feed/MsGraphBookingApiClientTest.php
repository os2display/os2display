<?php

declare(strict_types=1);

namespace App\Tests\Feed;

use App\Entity\Tenant\FeedSource;
use App\Feed\SourceType\MsGraph\ApiClient;
use App\Feed\SourceType\MsGraph\MsGraphException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MsGraphBookingApiClientTest extends TestCase
{
    private function source(): FeedSource
    {
        return (new FeedSource())->setSecrets([
            'tenant_id' => 'example.onmicrosoft.com',
            'client_id' => '11111111-2222-3333-4444-555555555555',
            'client_secret' => 'test-secret',
            'group_id' => '5391f5f7-111d-4452-a1b8-393b85399721',
        ]);
    }

    public function testUsesClientCredentialsForLiveAvailabilityAndEventCreation(): void
    {
        $calls = [];
        $responses = [
            ['access_token' => 'test-token', 'expires_in' => 3600],
            ['value' => [['scheduleId' => 'room@example.com', 'scheduleItems' => []]]],
            ['id' => 'created-event'],
        ];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls, &$responses): MockResponse {
            $calls[] = [$method, $url, $options];

            return new MockResponse(json_encode(array_shift($responses)), ['http_code' => 3 === count($calls) ? 201 : 200]);
        });
        $client = new ApiClient($http, new ArrayAdapter());
        $from = new \DateTimeImmutable('2030-01-01T10:00:00Z');
        $to = $from->modify('+30 minutes');
        $this->assertSame([], $client->getBusyIntervals($this->source(), 'room@example.com', $from, $to));
        $client->createRoomEvent($this->source(), 'room@example.com', $from, $to, 'request-id');

        $this->assertStringContainsString('grant_type=client_credentials', $calls[0][2]['body']);
        $this->assertStringContainsString('client_secret=test-secret', $calls[0][2]['body']);
        $this->assertSame('https://graph.microsoft.com/v1.0/users/room%40example.com/calendar/getSchedule', $calls[1][1]);
        $this->assertSame('https://graph.microsoft.com/v1.0/users/room%40example.com/events', $calls[2][1]);
        $body = json_decode($calls[2][2]['body'], true);
        $this->assertSame('Straksbooking', $body['subject']);
        $this->assertSame('busy', $body['showAs']);
        $this->assertSame(['dateTime' => '2030-01-01T10:00:00Z', 'timeZone' => 'UTC'], $body['start']);
        $this->assertSame(['dateTime' => '2030-01-01T10:30:00Z', 'timeZone' => 'UTC'], $body['end']);
        $this->assertSame('request-id', $body['transactionId']);
        $this->assertArrayNotHasKey('isOrganizer', $body);
        $this->assertArrayNotHasKey('attendees', $body);
    }

    /**
     * @dataProvider invalidAvailabilityProvider
     *
     * @param array<string, mixed> $response
     */
    public function testUnknownAvailabilityNeverMeansFree(array $response): void
    {
        $client = new ApiClient(new MockHttpClient([
            new MockResponse('{"access_token":"test","expires_in":3600}'),
            new MockResponse(json_encode($response)),
        ]), new ArrayAdapter());
        $this->expectException(MsGraphException::class);
        $client->getBusyIntervals($this->source(), 'room@example.com', new \DateTimeImmutable(), new \DateTimeImmutable('+1 hour'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidAvailabilityProvider(): iterable
    {
        yield 'missing room' => [['value' => []]];
        yield 'malformed room result' => [['value' => 'invalid']];
        yield 'other room' => [['value' => [['scheduleId' => 'other@example.com', 'scheduleItems' => []]]]];
        yield 'room error' => [['value' => [['scheduleId' => 'room@example.com', 'scheduleItems' => [], 'error' => ['message' => 'Denied']]]]];
        yield 'missing intervals' => [['value' => [['scheduleId' => 'room@example.com']]]];
        yield 'invalid interval' => [
            ['value' => [
                ['scheduleId' => 'room@example.com', 'scheduleItems' => [
                    ['status' => 'busy', 'start' => ['dateTime' => 'invalid'], 'end' => null],
                ]],
            ]],
        ];
    }

    public function testAvailabilityIsUncachedAndFreeEventsDoNotBlockBooking(): void
    {
        $schedule = ['value' => [['scheduleId' => 'room@example.com', 'scheduleItems' => [
            ['status' => 'free'],
            ['status' => 'workingElsewhere'],
            ['status' => 'tentative', 'start' => ['dateTime' => '2030-01-01T10:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2030-01-01T10:30:00', 'timeZone' => 'UTC']],
        ]]]];
        $http = new MockHttpClient([
            new MockResponse('{"access_token":"test","expires_in":3600}'),
            new MockResponse(json_encode($schedule)),
            new MockResponse(json_encode($schedule)),
        ]);
        $client = new ApiClient($http, new ArrayAdapter());
        $from = new \DateTimeImmutable('2030-01-01T10:00:00Z');
        $this->assertCount(1, $client->getBusyIntervals($this->source(), 'room@example.com', $from, $from->modify('+1 hour')));
        $client->getBusyIntervals($this->source(), 'room@example.com', $from, $from->modify('+1 hour'));
        $this->assertSame(3, $http->getRequestsCount());
    }

    /**
     * @dataProvider errorStatusProvider
     */
    public function testWriteErrorsAreSurfacedWithoutExposingGraphErrorBodies(int $status): void
    {
        $client = new ApiClient(new MockHttpClient([
            new MockResponse('{"access_token":"test","expires_in":3600}'),
            new MockResponse('{"error":{"message":"sensitive-detail"}}', ['http_code' => $status]),
        ]), new ArrayAdapter());
        try {
            $client->createRoomEvent($this->source(), 'room@example.com', new \DateTimeImmutable(), new \DateTimeImmutable('+30 minutes'), 'request-id');
            $this->fail('Expected write failure.');
        } catch (MsGraphException $exception) { // @phpstan-ignore logging.silentCatch (the test asserts the surfaced error and safe message)
            $this->assertSame($status, $exception->getCode());
            $this->assertStringNotContainsString('sensitive-detail', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function errorStatusProvider(): iterable
    {
        foreach ([401, 403, 409, 429, 500] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function testRoomInvalidationRefreshesAllCachedDateWindowsWithoutClearingTokenOrOtherRooms(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"access_token":"test","expires_in":3600}'),
            new MockResponse('{"value":[]}'),
            new MockResponse('{"value":[]}'),
            new MockResponse('{"value":[]}'),
            new MockResponse('{"value":[]}'),
            new MockResponse('{"value":[]}'),
        ]);
        $client = new ApiClient($http, new ArrayAdapter());
        $source = $this->source();
        $from = new \DateTimeImmutable('2030-01-01T00:00:00Z');
        $to = $from->modify('+7 days');
        $client->getEvents($source, 'room@example.com', $from, $to, 300, 'Optaget');
        $client->getEvents($source, 'room@example.com', $from, $to->modify('+1 day'), 300, 'Optaget');
        $client->getEvents($source, 'other@example.com', $from, $to, 300, 'Optaget');
        $this->assertSame(4, $http->getRequestsCount());
        $client->invalidateRoomEvents($source, 'room@example.com');
        $client->getEvents($source, 'room@example.com', $from, $to, 300, 'Optaget');
        $client->getEvents($source, 'room@example.com', $from, $to->modify('+1 day'), 300, 'Optaget');
        $client->getEvents($source, 'other@example.com', $from, $to, 300, 'Optaget');
        $this->assertSame(6, $http->getRequestsCount());
    }
}
