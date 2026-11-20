<?php

declare(strict_types=1);

namespace App\Tests\Interactive;

use App\Entity\Tenant;
use App\Entity\Tenant\Feed;
use App\Entity\Tenant\FeedSource;
use App\Entity\Tenant\InteractiveSlideConfig;
use App\Entity\Tenant\Slide;
use App\Exceptions\NotAcceptableException;
use App\Feed\CalendarApiFeedType;
use App\Feed\MsGraphFeedType;
use App\InteractiveSlide\InstantBook;
use App\InteractiveSlide\InteractionSlideRequest;
use App\InteractiveSlide\MsGraphBooking;
use App\Repository\InteractiveSlideRepository;
use App\Service\FeedService;
use App\Service\InteractiveSlideService;
use App\Service\KeyVaultService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class InstantBookRoutingTest extends TestCase
{
    /**
     * @dataProvider calendarApiCases
     */
    public function testCalendarApiKeepsItsAuthenticationAvailabilityAndBooking(string $action, string $busySource): void
    {
        $tenant = new Tenant();
        $tenant->setTenantKey('calendar-api');
        $source = (new FeedSource())->setFeedType(CalendarApiFeedType::class);
        $feed = (new Feed())->setFeedSource($source)->setConfiguration(['resources' => ['room@example.com']]);
        $slide = (new Slide())->setFeed($feed);
        $config = (new InteractiveSlideConfig())->setConfiguration([
            'tenantId' => 'tenant-key', 'clientId' => 'client-key',
            'username' => 'username-key', 'password' => 'password-key',
        ]);
        $config->setImplementationClass(InstantBook::class);
        $repo = $this->createMock(InteractiveSlideRepository::class);
        $repo->method('findOneBy')->willReturn($config);
        $interactives = new InteractiveSlideService([], $repo, $this->createMock(EntityManagerInterface::class));
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options];
            if (str_contains($url, '/token')) {
                return new MockResponse('{"access_token":"calendar-api-token","expires_in":3600}');
            }
            if (str_ends_with($url, '/getSchedule')) {
                return new MockResponse('{"value":[{"scheduleId":"room@example.com","scheduleItems":[]}]}');
            }

            return new MockResponse('{"id":"booking"}', ['http_code' => 201]);
        });
        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getData')->willReturn([]);
        $graphType = $this->createMock(MsGraphFeedType::class);
        $graphType->method('getSupportedFeedOutputType')->willReturn('calendar');
        $feedService->method('getFeedType')->willReturn($graphType);
        $msBooking = $this->createMock(MsGraphBooking::class);
        $msBooking->expects($this->never())->method('performAction');
        $booker = new InstantBook($interactives, $http, new KeyVaultService(KeyVaultService::ENVIRONMENT, [
            'tenant-key' => 'calendar-api-tenant', 'client-key' => 'calendar-api-client',
            'username-key' => 'calendar-api@example.com', 'password-key' => 'calendar-api-password',
        ]), new ArrayAdapter(), $feedService, $busySource, new NullLogger(), $msBooking);
        $request = new InteractionSlideRequest(InstantBook::class, $action, 'ACTION_QUICK_BOOK' === $action
            ? ['interval' => ['resource' => 'room@example.com', 'durationMinutes' => 30]]
            : ['resource' => 'room@example.com']);
        $result = $booker->performAction($tenant, $slide, $request);

        $this->assertSame('https://login.microsoftonline.com/calendar-api-tenant/oauth2/v2.0/token', $calls[0][1]);
        $this->assertStringContainsString('grant_type=password', $calls[0][2]['body']);
        $this->assertStringContainsString('username=calendar-api%40example.com', $calls[0][2]['body']);
        if ('ACTION_QUICK_BOOK' === $action) {
            $this->assertSame(201, $result['status']);
            $this->assertSame('https://graph.microsoft.com/v1.0/me/calendar/getSchedule', $calls[1][1]);
            $this->assertSame('https://graph.microsoft.com/v1.0/users/room@example.com/events', $calls[2][1]);
            $body = json_decode($calls[2][2]['body'], true);
            $this->assertSame('calendar-api@example.com', $body['attendees'][0]['emailAddress']['address']);
        } else {
            $this->assertSame([15, 30, 60], array_column($result['options'], 'durationMinutes'));
            $this->assertCount(InstantBook::SOURCE_FEED === $busySource ? 1 : 2, $calls);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function calendarApiCases(): iterable
    {
        foreach (['ACTION_GET_QUICK_BOOK_OPTIONS', 'ACTION_QUICK_BOOK'] as $action) {
            foreach ([InstantBook::SOURCE_GRAPH, InstantBook::SOURCE_FEED] as $source) {
                yield $action.' '.$source => [$action, $source];
            }
        }
    }

    public function testMsGraphRoutesBothActionsWithoutCallingCalendarApiAuthentication(): void
    {
        $tenant = new Tenant();
        $slide = (new Slide())->setFeed((new Feed())->setFeedSource((new FeedSource())->setFeedType(MsGraphFeedType::class)));
        $msBooking = $this->createMock(MsGraphBooking::class);
        $msBooking->expects($this->exactly(2))->method('performAction')->with($tenant, $slide)->willReturn(['test' => true]);
        $http = new MockHttpClient(static function (): never {
            throw new \LogicException('MS Graph feed bookings must not use Calendar API credentials.');
        });
        $interactives = new InteractiveSlideService([], $this->createMock(InteractiveSlideRepository::class), $this->createMock(EntityManagerInterface::class));
        $booker = new InstantBook($interactives, $http, new KeyVaultService(KeyVaultService::ENVIRONMENT, []), new ArrayAdapter(), $this->createMock(FeedService::class), InstantBook::SOURCE_FEED, new NullLogger(), $msBooking);
        foreach (['ACTION_GET_QUICK_BOOK_OPTIONS', 'ACTION_QUICK_BOOK'] as $action) {
            $this->assertSame(['test' => true], $booker->performAction($tenant, $slide, new InteractionSlideRequest(InstantBook::class, $action, [])));
        }
    }

    public function testTenantInteractiveGateStillRejectsUnconfiguredBookings(): void
    {
        $repo = $this->createMock(InteractiveSlideRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $msBooking = $this->createMock(MsGraphBooking::class);
        $msBooking->expects($this->never())->method('performAction');
        $service = new InteractiveSlideService([], $repo, $this->createMock(EntityManagerInterface::class));
        $this->expectException(NotAcceptableException::class);
        $service->performAction(new Tenant(), new Slide(), new InteractionSlideRequest(InstantBook::class, 'ACTION_QUICK_BOOK', []));
    }
}
