<?php

declare(strict_types=1);

namespace App\Tests\Feed;

use App\Entity\Tenant;
use App\Entity\Tenant\Feed;
use App\Entity\Tenant\FeedSource;
use App\Feed\FeedConfigurationException;
use App\Feed\FeedOutputModels;
use App\Feed\MsGraphFeedType;
use App\Feed\OutputModel\Calendar\CalendarEvent;
use App\Feed\OutputModel\Calendar\Resource;
use App\Feed\SourceType\MsGraph\ApiClient;
use App\Feed\SourceType\MsGraph\MsGraphException;
use App\Feed\SourceType\MsGraph\SecretsDTO;
use App\Service\FeedService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

class MsGraphFeedTypeTest extends TestCase
{
    private const string ROOM_1 = 'room1@example.com';
    private const string ROOM_2 = 'room2@example.com';

    public function testSupportedFeedOutputTypeIsCalendar(): void
    {
        $this->assertSame(FeedOutputModels::CALENDAR_OUTPUT, $this->createFeedType($this->createMock(ApiClient::class))->getSupportedFeedOutputType());
    }

    public function testGetDataReturnsCalendarOutputSortedByStartTime(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getRooms')->willReturn($this->sampleRooms());
        $apiClient->method('getEvents')->willReturnCallback(
            fn (FeedSource $feedSource, string $roomEmail): array => match ($roomEmail) {
                self::ROOM_1 => [new CalendarEvent('event-1', 'Møde A', 200, 300, self::ROOM_1, self::ROOM_1)],
                self::ROOM_2 => [new CalendarEvent('event-2', 'Møde B', 100, 150, self::ROOM_2, self::ROOM_2)],
                default => [],
            },
        );

        $data = $this->createFeedType($apiClient)->getData($this->createFeed(['resources' => [self::ROOM_1, self::ROOM_2]]));

        $this->assertSame([100, 200], array_column($data, 'startTime'));
        $this->assertSame(['Møde B', 'Møde A'], array_column($data, 'title'));
        $this->assertSame(['Lokale 2', 'Lokale 1'], array_column($data, 'resourceTitle'));
        $this->assertSame([self::ROOM_2, self::ROOM_1], array_column($data, 'resourceId'));
        $this->assertSame([150, 300], array_column($data, 'endTime'));
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{26}$/', $data[0]['id']);
    }

    public function testGetDataRethrowsRoomEligibilityLookupFailure(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getRooms')->willThrowException(new MsGraphException('Place.Read.All not granted'));
        $apiClient->expects($this->never())->method('getEvents');

        $this->expectException(MsGraphException::class);
        $this->createFeedType($apiClient)->getData($this->createFeed(['resources' => [self::ROOM_1]]));
    }

    public function testPreviouslySelectedRoomOutsideGroupIsRejectedBeforeReadingEvents(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getRooms')->willReturn([]);
        $apiClient->expects($this->never())->method('getEvents');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Selected room is not a member of the configured group');
        $this->createFeedType($apiClient)->getData($this->createFeed(['resources' => [self::ROOM_1]]));
    }

    /**
     * @dataProvider provideInvalidGroupIds
     */
    public function testInvalidGroupIdIsRejected(?string $groupId): void
    {
        $source = $this->createFeedSource();
        $secrets = $source->getSecrets();
        if (null === $groupId) {
            unset($secrets['group_id']);
        } else {
            $secrets['group_id'] = $groupId;
        }
        $source->setSecrets($secrets);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid group_id');
        new SecretsDTO($source);
    }

    /**
     * @return iterable<array{?string}>
     */
    public function provideInvalidGroupIds(): iterable
    {
        yield [null];
        yield [''];
        yield ['group@example.com'];
        yield ['5391f5f7-111d-4452-a1b8-393b8539972'];
    }

    public function testGroupMembershipIsPagedFilteredAndCachedByGroup(): void
    {
        $groupRequests = [];
        $directoryRequests = 0;
        $firstGroup = '5391f5f7-111d-4452-a1b8-393b85399721';
        $secondGroup = '5391f5f7-111d-4452-a1b8-393b85399722';
        $next = ApiClient::GRAPH_ENDPOINT.'/groups/'.$firstGroup.'/members?$skiptoken=next';
        $http = new MockHttpClient(static function (string $method, string $url) use (&$groupRequests, &$directoryRequests, $firstGroup, $next): MockResponse {
            if ('POST' === $method) {
                return new MockResponse('{"access_token":"test-token","expires_in":3600}');
            }
            if (str_contains($url, '/places/')) {
                ++$directoryRequests;

                return new MockResponse('{"value":[{"emailAddress":"room1@example.com","displayName":"Room 1"},{"emailAddress":"room2@example.com","displayName":"Room 2"},{"emailAddress":"outside@example.com"}]}');
            }
            $groupRequests[] = $url;
            if ($url === $next) {
                return new MockResponse('{"value":[{"@odata.type":"#microsoft.graph.user","mail":"room2@example.com"}]}');
            }
            if (str_contains($url, $firstGroup)) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                self::assertSame('id,displayName,mail', $query['$select']);

                return new MockResponse(json_encode([
                    'value' => [
                        ['@odata.type' => '#microsoft.graph.user', 'mail' => 'ROOM1@example.com'],
                        ['@odata.type' => '#microsoft.graph.user', 'mail' => 'person@example.com'],
                        ['@odata.type' => '#microsoft.graph.group', 'mail' => 'outside@example.com'],
                    ],
                    '@odata.nextLink' => $next,
                ], JSON_THROW_ON_ERROR));
            }

            return new MockResponse('{"value":[]}');
        });
        $client = new ApiClient($http, new ArrayAdapter());
        $source = $this->createFeedSource();
        $this->assertSame([self::ROOM_1, self::ROOM_2], array_keys($client->getRooms($source)));
        $this->assertCount(2, $client->getRooms($source));
        $source->setSecrets(array_merge($source->getSecrets(), ['group_id' => $secondGroup]));
        $this->assertSame([], $client->getRooms($source));
        $this->assertCount(3, $groupRequests);
        $this->assertSame(1, $directoryRequests);
    }

    public function testGroupMembersWithoutVisibleMailFailExplicitly(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(
            'POST' === $method ? '{"access_token":"test-token","expires_in":3600}' :
                (str_contains($url, '/groups/') ? '{"value":[{"@odata.type":"#microsoft.graph.user","id":"member","mail":null}]}' : '{"value":[]}'),
        ));
        $this->expectException(MsGraphException::class);
        $this->expectExceptionMessage('member mail is unavailable');
        new ApiClient($http, new ArrayAdapter())->getRooms($this->createFeedSource());
    }

    public function testGetDataRequestsSevenDaysFromMidnightToday(): void
    {
        $capturedFrom = null;
        $capturedTo = null;

        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getRooms')->willReturn($this->sampleRooms());
        $apiClient->method('getEvents')->willReturnCallback(
            function (FeedSource $feedSource, string $roomEmail, \DateTimeImmutable $from, \DateTimeImmutable $to) use (&$capturedFrom, &$capturedTo): array {
                $capturedFrom = $from;
                $capturedTo = $to;

                return [];
            },
        );

        $this->createFeedType($apiClient)->getData($this->createFeed(['resources' => [self::ROOM_1]]));

        $expectedFrom = new \DateTimeImmutable('today midnight');

        $this->assertEquals($expectedFrom, $capturedFrom);
        $this->assertEquals($expectedFrom->add(new \DateInterval('P7D')), $capturedTo);
        $this->assertSame('00:00:00', $capturedTo?->format('H:i:s'));
    }

    public function testGetDataPassesPerFeedCacheTtlToApiClient(): void
    {
        $capturedTtl = null;

        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getRooms')->willReturn($this->sampleRooms());
        $apiClient->method('getEvents')->willReturnCallback(
            function (FeedSource $feedSource, string $roomEmail, \DateTimeImmutable $from, \DateTimeImmutable $to, int $cacheTtlSeconds) use (&$capturedTtl): array {
                $capturedTtl = $cacheTtlSeconds;

                return [];
            },
        );

        $this->createFeedType($apiClient)->getData($this->createFeed([
            'resources' => [self::ROOM_1],
            'cache_expire_minutes' => 30,
        ]));

        $this->assertSame(1800, $capturedTtl);
    }

    /**
     * @dataProvider provideCacheExpireCases
     *
     * @param array<string, mixed>|null $configuration
     */
    public function testGetCacheExpireSeconds(?array $configuration, int $expected): void
    {
        $this->assertSame($expected, MsGraphFeedType::getCacheExpireSeconds($configuration));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>|null, 1: int}>
     */
    public function provideCacheExpireCases(): iterable
    {
        yield 'default when not configured' => [[], MsGraphFeedType::DEFAULT_CACHE_EXPIRE_MINUTES * 60];
        yield 'default when configuration is null' => [null, MsGraphFeedType::DEFAULT_CACHE_EXPIRE_MINUTES * 60];
        yield 'default when not numeric' => [['cache_expire_minutes' => 'abc'], MsGraphFeedType::DEFAULT_CACHE_EXPIRE_MINUTES * 60];
        yield 'numeric string from the admin form' => [['cache_expire_minutes' => '15'], 900];
        yield 'clamped to minimum' => [['cache_expire_minutes' => 0], MsGraphFeedType::MIN_CACHE_EXPIRE_MINUTES * 60];
        yield 'negative is clamped to minimum' => [['cache_expire_minutes' => -10], MsGraphFeedType::MIN_CACHE_EXPIRE_MINUTES * 60];
        yield 'clamped to maximum' => [['cache_expire_minutes' => 100000], MsGraphFeedType::MAX_CACHE_EXPIRE_MINUTES * 60];
    }

    /**
     * The error-cache contract: getData must rethrow so FeedService caches the
     * empty result for 30s instead of the full feed TTL.
     */
    public function testGetDataThrowsWhenResourcesAreNotSet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MsGraphFeedType: Resources not set.');

        $this->createFeedType($this->createMock(ApiClient::class))->getData($this->createFeed([]));
    }

    public function testGetDataRethrowsApiClientFailures(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getRooms')->willReturn($this->sampleRooms());
        $apiClient->method('getEvents')->willThrowException(new MsGraphException('MS Graph request failed'));

        $this->expectException(MsGraphException::class);

        $this->createFeedType($apiClient)->getData($this->createFeed(['resources' => [self::ROOM_1]]));
    }

    public function testGetAdminFormOptionsExposesResourceSelectorAndCacheField(): void
    {
        $options = $this->createFeedType($this->createMock(ApiClient::class))->getAdminFormOptions($this->createFeedSource());

        $resourceSelector = $this->findFormOption($options, 'resources');
        $cacheField = $this->findFormOption($options, 'cache_expire_minutes');

        $this->assertSame('multiselect-from-endpoint', $resourceSelector['input'] ?? null);
        $this->assertSame('https://example.com/config/resources', $resourceSelector['endpoint'] ?? null);
        $this->assertSame('input', $cacheField['input'] ?? null);
        $this->assertSame('number', $cacheField['type'] ?? null);
        $this->assertSame(MsGraphFeedType::DEFAULT_CACHE_EXPIRE_MINUTES, $cacheField['defaultValue'] ?? null);
    }

    public function testGetConfigOptionsReturnsRoomsForResources(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getRooms')->willReturn($this->sampleRooms());

        $options = $this->createFeedType($apiClient)->getConfigOptions(new Request(), $this->createFeedSource(), 'resources');

        $this->assertSame(['Lokale 1 ('.self::ROOM_1.')', 'Lokale 2 ('.self::ROOM_2.')'], array_column($options ?? [], 'title'));
        $this->assertSame([self::ROOM_1, self::ROOM_2], array_column($options ?? [], 'value'));
    }

    public function testGetConfigOptionsReturnsNullForUnknownName(): void
    {
        $this->assertNull($this->createFeedType($this->createMock(ApiClient::class))->getConfigOptions(new Request(), $this->createFeedSource(), 'unknown'));
    }

    /**
     * @dataProvider provideConfigurationFailureCases
     */
    public function testGetConfigOptionsReportsSafeErrors(int $status, string $expectedMessage): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getRooms')->willThrowException(new MsGraphException('Sensitive provider response', $status));

        $this->expectException(FeedConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->createFeedType($apiClient)->getConfigOptions(new Request(), $this->createFeedSource(), 'resources');
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public function provideConfigurationFailureCases(): iterable
    {
        yield 'invalid credentials' => [400, 'Kontrollér Tenant ID, Client ID og Client Secret'];
        yield 'unauthorized' => [401, 'Place.Read.All med administratorsamtykke'];
        yield 'forbidden' => [403, 'Microsoft Graph nægtede adgang til lokalerne'];
        yield 'throttled' => [429, 'Vent lidt, og prøv igen'];
        yield 'connection failure' => [0, 'Forbindelsen til Microsoft Graph kunne ikke oprettes'];
    }

    public function testPrivateSensitivityAndPrivateSubjectReplaceTheEventTitle(): void
    {
        $calendarQuery = [];
        $events = [
            [
                'subject' => 'Planning',
                'sensitivity' => 'private',
                'start' => ['dateTime' => '2026-10-25T10:00:00', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-10-25T11:00:00', 'timeZone' => 'UTC'],
            ],
            [
                'subject' => 'Privat aftale',
                'sensitivity' => 'normal',
                'start' => ['dateTime' => '2026-10-25T11:00:00', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-10-25T12:00:00', 'timeZone' => 'UTC'],
            ],
            [
                'subject' => 'privat appointment',
                'sensitivity' => 'normal',
                'start' => ['dateTime' => '2026-10-25T12:00:00', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-10-25T13:00:00', 'timeZone' => 'UTC'],
            ],
            [
                'subject' => 'Planning',
                'sensitivity' => 'normal',
                'start' => ['dateTime' => '2026-10-25T13:00:00', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-10-25T14:00:00', 'timeZone' => 'UTC'],
            ],
        ];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$calendarQuery, $events): MockResponse {
            if ('POST' === $method) {
                return new MockResponse('{"access_token":"test-token","expires_in":3600}');
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $calendarQuery);

            return new MockResponse(json_encode(['value' => $events], JSON_THROW_ON_ERROR));
        });

        $result = (new ApiClient($http, new ArrayAdapter()))->getEvents(
            $this->createFeedSource(),
            self::ROOM_1,
            new \DateTimeImmutable('2026-10-25T00:00:00Z'),
            new \DateTimeImmutable('2026-11-01T00:00:00Z'),
            300,
            MsGraphFeedType::DEFAULT_EVENT_TITLE,
        );

        $this->assertSame([
            MsGraphFeedType::DEFAULT_EVENT_TITLE,
            MsGraphFeedType::DEFAULT_EVENT_TITLE,
            MsGraphFeedType::DEFAULT_EVENT_TITLE,
            'Planning',
        ], array_column($result, 'title'));
        $this->assertStringContainsString('sensitivity', $calendarQuery['$select']);
    }

    public function testRoomDiscoveryContinuesWithoutNextLink(): void
    {
        $rooms = array_map(static fn (int $number): array => [
            'emailAddress' => 'room'.$number.'@example.com',
            'displayName' => 'Room '.$number,
        ], range(1, 100));
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requests, $rooms): MockResponse {
            $requests[] = $url;
            if ('POST' === $method) {
                return new MockResponse('{"access_token":"test-token","expires_in":3600}');
            }
            if (str_contains($url, '/groups/')) {
                return new MockResponse(json_encode(['value' => array_map(static fn (array $room): array => [
                    '@odata.type' => '#microsoft.graph.user', 'mail' => $room['emailAddress'],
                ], array_merge($rooms, [['emailAddress' => 'last@example.com']]))], JSON_THROW_ON_ERROR));
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('100', $query['$top']);

            return new MockResponse(json_encode(['value' => isset($query['$skip']) ? [
                ['emailAddress' => 'last@example.com', 'displayName' => 'Last room'],
            ] : $rooms], JSON_THROW_ON_ERROR));
        });

        $client = new ApiClient($http, new ArrayAdapter());
        $result = $client->getRooms($this->createFeedSource());

        $this->assertCount(101, $result);
        $this->assertArrayHasKey('last@example.com', $result);
        parse_str((string) parse_url($requests[3], PHP_URL_QUERY), $lastQuery);
        $this->assertSame('100', $lastQuery['$skip']);
    }

    public function testRoomDiscoveryFollowsNextLinkWithoutReplacingItsQuery(): void
    {
        $next = ApiClient::GRAPH_ENDPOINT.'/places/microsoft.graph.room?$skiptoken=cursor';
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requests, $next): MockResponse {
            $requests[] = $url;
            if ('POST' === $method) {
                return new MockResponse('{"access_token":"test-token","expires_in":3600}');
            }
            if (str_contains($url, '/groups/')) {
                return new MockResponse('{"value":[{"@odata.type":"#microsoft.graph.user","mail":"first@example.com"},{"@odata.type":"#microsoft.graph.user","mail":"second@example.com"}]}');
            }

            return new MockResponse(json_encode([
                'value' => [['emailAddress' => $url === $next ? 'second@example.com' : 'first@example.com']],
                '@odata.nextLink' => $url === $next ? null : $next,
            ], JSON_THROW_ON_ERROR));
        });

        $rooms = (new ApiClient($http, new ArrayAdapter()))->getRooms($this->createFeedSource());

        $this->assertCount(2, $rooms);
        $this->assertSame($next, $requests[3]);
    }

    public function testRoomDiscoveryFailsRatherThanReturningTruncatedResults(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(
            'POST' === $method ? '{"access_token":"test-token","expires_in":3600}' : (str_contains($url, '/groups/') ? '{"value":[]}' : json_encode([
                'value' => array_fill(0, 100, ['emailAddress' => 'room@example.com']),
            ], JSON_THROW_ON_ERROR)),
        ));

        $this->expectException(MsGraphException::class);
        $this->expectExceptionMessage('refusing to return an incomplete result');

        (new ApiClient($http, new ArrayAdapter()))->getRooms($this->createFeedSource());
    }

    public function testRoomDiscoveryFailureDoesNotExposeProviderResponse(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getRooms')->willThrowException(new MsGraphException('Sensitive provider response', 401));

        try {
            $this->createFeedType($apiClient)->getConfigOptions(new Request(), $this->createFeedSource(), 'resources');
            $this->fail('Expected a safe configuration error.');
        } catch (FeedConfigurationException $exception) {
            $this->assertStringContainsString('Place.Read.All', $exception->getMessage());
            $this->assertStringNotContainsString('Sensitive provider response', $exception->getMessage());
        }
    }

    public function testGetRequiredSecretsOnlyExposesNonSensitiveValues(): void
    {
        $secrets = $this->createFeedType($this->createMock(ApiClient::class))->getRequiredSecrets();

        $this->assertSame(['tenant_id', 'client_id', 'client_secret', 'group_id'], array_keys($secrets));
        $this->assertArrayNotHasKey('exposeValue', $secrets['client_secret']);
        $this->assertArrayNotHasKey('exposeValue', $secrets['tenant_id']);
        $this->assertArrayNotHasKey('exposeValue', $secrets['client_id']);
        $this->assertTrue($secrets['group_id']['exposeValue']);
    }

    /**
     * @param list<array<string, mixed>> $options
     *
     * @return array<string, mixed>|null
     */
    private function findFormOption(array $options, string $name): ?array
    {
        foreach ($options as $option) {
            if (($option['name'] ?? null) === $name) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return array<string, \App\Feed\OutputModel\Calendar\Resource>
     */
    private function sampleRooms(): array
    {
        return [
            self::ROOM_2 => new Resource(self::ROOM_2, self::ROOM_2, '', 'Lokale 2'),
            self::ROOM_1 => new Resource(self::ROOM_1, self::ROOM_1, '', 'Lokale 1'),
        ];
    }

    private function createFeedType(ApiClient $apiClient): MsGraphFeedType
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedSourceConfigUrl')->willReturnCallback(
            static fn (FeedSource $feedSource, string $name): string => 'https://example.com/config/'.$name,
        );

        return new MsGraphFeedType($feedService, $apiClient, $this->createMock(LoggerInterface::class));
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createFeed(array $configuration): Feed
    {
        $feed = $this->createMock(Feed::class);
        $feed->method('getConfiguration')->willReturn($configuration);
        $feed->method('getFeedSource')->willReturn($this->createFeedSource());
        $feed->method('getTenant')->willReturn($this->createTenant());

        return $feed;
    }

    private function createFeedSource(): FeedSource
    {
        $feedSource = new FeedSource();
        $feedSource->setSecrets([
            'tenant_id' => 'contoso.onmicrosoft.com',
            'client_id' => '11111111-2222-3333-4444-555555555555',
            'client_secret' => 'secret',
            'group_id' => '5391f5f7-111d-4452-a1b8-393b85399721',
        ]);
        $feedSource->setTenant($this->createTenant());

        return $feedSource;
    }

    private function createTenant(): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getTenantKey')->willReturn('test');

        return $tenant;
    }
}
