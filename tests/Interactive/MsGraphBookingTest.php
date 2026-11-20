<?php

declare(strict_types=1);

namespace App\Tests\Interactive;

use App\Entity\Tenant;
use App\Entity\Tenant\Feed;
use App\Entity\Tenant\FeedSource;
use App\Entity\Tenant\Slide;
use App\Exceptions\BadRequestException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotAcceptableException;
use App\Exceptions\TooManyRequestsException;
use App\Feed\MsGraphFeedType;
use App\Feed\OutputModel\Calendar\Resource;
use App\Feed\SourceType\MsGraph\ApiClient;
use App\Feed\SourceType\MsGraph\MsGraphException;
use App\InteractiveSlide\InstantBook;
use App\InteractiveSlide\InteractionSlideRequest;
use App\InteractiveSlide\MsGraphBooking;
use App\Repository\FeedSourceRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Ulid;

class MsGraphBookingTest extends TestCase
{
    private const string ROOM = 'room@example.com';
    private Tenant $tenant;
    private Slide $slide;
    private FeedSource $source;
    private ApiClient&MockObject $client;
    private ArrayAdapter $feedsCache;
    private MsGraphBooking $booking;
    private EntityManagerInterface&MockObject $manager;
    /** @var list<FeedSource> */
    private array $sources;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->tenant->setId(new Ulid());
        $this->source = new FeedSource();
        $this->source->setId(new Ulid());
        $this->source->setTenant($this->tenant);
        $this->source->setFeedType(MsGraphFeedType::class)->setSecrets([
            'tenant_id' => 'example.onmicrosoft.com',
            'client_id' => '11111111-2222-3333-4444-555555555555',
            'client_secret' => 'test-secret',
        ]);
        $feed = new Feed();
        $feed->setId(new Ulid());
        $feed->setTenant($this->tenant);
        $feed->setConfiguration(['resources' => [self::ROOM]]);
        $this->source->addFeed($feed);
        $this->slide = new Slide();
        $this->slide->setId(new Ulid());
        $this->slide->setTenant($this->tenant);
        $this->slide->setFeed($feed)->setContent(['layout' => 'singleBooking', 'instantBookingEnabled' => true]);
        $this->client = $this->createMock(ApiClient::class);
        $this->client->method('getRooms')->willReturn([self::ROOM => new Resource(self::ROOM, self::ROOM, '', 'Room')]);

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work());
        $manager = $this->createMock(EntityManagerInterface::class);
        $this->manager = $manager;
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('lock')->with($this->tenant, LockMode::PESSIMISTIC_WRITE);
        $sources = $this->createMock(FeedSourceRepository::class);
        $this->sources = [$this->source];
        $sources->method('findBy')->with(['tenant' => $this->tenant, 'feedType' => MsGraphFeedType::class])->willReturnCallback(fn (): array => $this->sources);
        $this->feedsCache = new ArrayAdapter();
        $this->booking = new MsGraphBooking($this->client, $manager, $sources, new ArrayAdapter(), $this->feedsCache, new NullLogger());
    }

    public function testOptionsExcludeDurationsThatOverlapLiveEvents(): void
    {
        $this->client->expects($this->once())->method('getBusyIntervals')->willReturnCallback(
            static fn (FeedSource $source, string $room, \DateTimeImmutable $from): array => [
                ['startTime' => $from->modify('+20 minutes'), 'endTime' => $from->modify('+40 minutes')],
            ],
        );
        $this->client->expects($this->never())->method('createRoomEvent');

        $result = $this->booking->performAction($this->tenant, $this->slide, $this->options());
        $this->assertSame([15], array_column($result['options'], 'durationMinutes'));
    }

    public function testBookingIsIdempotentAndInvalidatesOtherFeedsForTheRoom(): void
    {
        $this->manager->expects($this->exactly(2))->method('lock')->with($this->tenant, LockMode::PESSIMISTIC_WRITE);
        $other = new Feed();
        $other->setId(new Ulid());
        $other->setTenant($this->tenant);
        $other->setConfiguration(['resources' => [self::ROOM]]);
        $this->source->addFeed($other);
        $unrelated = new Feed();
        $unrelated->setId(new Ulid());
        $unrelated->setTenant($this->tenant);
        $unrelated->setConfiguration(['resources' => ['other@example.com']]);
        $this->source->addFeed($unrelated);
        foreach ($this->source->getFeeds() as $feed) {
            $this->feedsCache->get((string) $feed->getId(), static fn (): array => ['stale']);
        }
        $this->client->expects($this->once())->method('getBusyIntervals')->willReturn([]);
        $this->client->expects($this->once())->method('createRoomEvent')->with(
            $this->source, self::ROOM,
            $this->callback(static fn (\DateTimeImmutable $from): bool => 0 === $from->getOffset()),
            $this->isInstanceOf(\DateTimeImmutable::class),
            $this->matchesRegularExpression('/^[a-f0-9]{64}$/'),
        );
        $this->client->expects($this->atLeastOnce())->method('invalidateRoomEvents')->with($this->source, self::ROOM);
        $request = $this->book();

        $first = $this->booking->performAction($this->tenant, $this->slide, $request);
        $this->assertSame($first, $this->booking->performAction($this->tenant, $this->slide, $request));
        $this->assertSame(201, $first['status']);
        $this->assertSame(1800, strtotime($first['interval']['to']) - strtotime($first['interval']['from']));
        $this->assertFalse($this->feedsCache->hasItem((string) $other->getId()));
        $this->assertTrue($this->feedsCache->hasItem((string) $unrelated->getId()));
    }

    public function testBusyRoomIsRejectedWithoutWriting(): void
    {
        $this->client->method('getBusyIntervals')->willReturnCallback(
            static fn (FeedSource $source, string $room, \DateTimeImmutable $from, \DateTimeImmutable $to): array => [
                ['startTime' => $from, 'endTime' => $to],
            ],
        );
        $this->client->expects($this->never())->method('createRoomEvent');
        $this->expectException(ConflictException::class);
        $this->booking->performAction($this->tenant, $this->slide, $this->book());
    }

    public function testBookingInvalidatesOtherSourcesUsingDifferentCredentialsForTheSameRoom(): void
    {
        $otherSource = (new FeedSource())->setFeedType(MsGraphFeedType::class);
        $otherSource->setId(new Ulid());
        $otherSource->setTenant($this->tenant);
        $otherSource->setSecrets(array_merge($this->source->getSecrets(), ['client_secret' => 'different-secret']));
        $feed = (new Feed())->setConfiguration(['resources' => [self::ROOM]]);
        $feed->setId(new Ulid());
        $feed->setTenant($this->tenant);
        $otherSource->addFeed($feed);
        $this->sources[] = $otherSource;
        $this->feedsCache->get((string) $feed->getId(), static fn (): array => ['stale']);
        $this->client->method('getBusyIntervals')->willReturn([]);
        $invalidated = [];
        $this->client->expects($this->exactly(2))->method('invalidateRoomEvents')->willReturnCallback(
            static function (FeedSource $source, string $resource) use (&$invalidated): void {
                $invalidated[] = [(string) $source->getId(), $resource];
            },
        );
        $this->booking->performAction($this->tenant, $this->slide, $this->book());
        $this->assertSame([[(string) $this->source->getId(), self::ROOM], [(string) $otherSource->getId(), self::ROOM]], $invalidated);
        $this->assertFalse($this->feedsCache->hasItem((string) $feed->getId()));
    }

    public function testUnknownAvailabilitySurfacesAnErrorAndNeverWrites(): void
    {
        $this->client->method('getBusyIntervals')->willThrowException(new MsGraphException('Room availability unavailable'));
        $this->client->expects($this->never())->method('createRoomEvent');
        $this->expectException(HttpException::class);
        $this->booking->performAction($this->tenant, $this->slide, $this->book());
    }

    public function testTimeoutRetryUsesIdenticalTimesAndTransactionIdWithoutRecheckingItsOwnEvent(): void
    {
        $this->client->expects($this->once())->method('getBusyIntervals')->willReturn([]);
        $calls = [];
        $this->client->expects($this->exactly(2))->method('createRoomEvent')->willReturnCallback(
            static function (FeedSource $source, string $room, \DateTimeImmutable $from, \DateTimeImmutable $to, string $id) use (&$calls): void {
                $calls[] = [$from, $to, $id];
                if (1 === count($calls)) {
                    throw new MsGraphException('Timeout');
                }
            },
        );
        $request = $this->book();
        try {
            $this->booking->performAction($this->tenant, $this->slide, $request);
            $this->fail('Expected the timeout to be surfaced.');
        } catch (HttpException $exception) { // @phpstan-ignore logging.silentCatch (the test asserts the surfaced HTTP error)
            $this->assertSame(502, $exception->getStatusCode());
        }
        $result = $this->booking->performAction($this->tenant, $this->slide, $request);
        $this->assertEquals($calls[0], $calls[1]);
        $this->assertSame(201, $result['status']);
    }

    public function testRateLimitDoesNotAffectRetriesButRejectsAnotherNewRequest(): void
    {
        $this->client->method('getBusyIntervals')->willReturn([]);
        $this->client->expects($this->once())->method('createRoomEvent');
        $this->booking->performAction($this->tenant, $this->slide, $this->book());
        $this->expectException(TooManyRequestsException::class);
        $this->booking->performAction($this->tenant, $this->slide, $this->book());
    }

    public function testRequestIdCannotBeReusedForAnotherDuration(): void
    {
        $this->client->method('getBusyIntervals')->willReturn([]);
        $request = $this->book();
        $this->booking->performAction($this->tenant, $this->slide, $request);
        $data = $request->data;
        $data['interval']['durationMinutes'] = 60;
        $this->expectException(BadRequestException::class);
        $this->booking->performAction($this->tenant, $this->slide, new InteractionSlideRequest(InstantBook::class, 'ACTION_QUICK_BOOK', $data));
    }

    /**
     * @dataProvider invalidSlideProvider
     */
    public function testInvalidSlidesCannotReadAvailabilityOrBook(string $case, bool $book): void
    {
        $otherTenant = new Tenant();
        $otherTenant->setId(new Ulid());
        match ($case) {
            'disabled' => $this->slide->setContent(['layout' => 'singleBooking', 'instantBookingEnabled' => false]),
            'layout' => $this->slide->setContent(['layout' => 'single', 'instantBookingEnabled' => true]),
            'multiple' => $this->slide->getFeed()->setConfiguration(['resources' => [self::ROOM, 'other@example.com']]),
            'wrong-room' => $this->slide->getFeed()->setConfiguration(['resources' => ['other@example.com']]),
            'tenant' => $this->source->setTenant($otherTenant),
            'slide-tenant' => $this->slide->setTenant($otherTenant),
            'feed-tenant' => $this->slide->getFeed()->setTenant($otherTenant),
            default => throw new \InvalidArgumentException('Unknown test case'),
        };
        $this->client->expects($this->never())->method('getBusyIntervals');
        $this->client->expects($this->never())->method('createRoomEvent');
        $this->expectException(NotAcceptableException::class);
        $this->booking->performAction($this->tenant, $this->slide, $book ? $this->book() : $this->options());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function invalidSlideProvider(): iterable
    {
        foreach (['disabled', 'layout', 'multiple', 'wrong-room', 'tenant', 'slide-tenant', 'feed-tenant'] as $case) {
            yield $case.' options' => [$case, false];
            yield $case.' booking' => [$case, true];
        }
    }

    public function testRoomOutsideSelectedRoomListsIsRejected(): void
    {
        $this->source->setSecrets(array_merge($this->source->getSecrets(), ['locations' => ['building@example.com']]));
        $client = $this->createMock(ApiClient::class);
        $client->expects($this->once())->method('getRooms')->with($this->source, 'building@example.com')->willReturn([]);
        $client->expects($this->never())->method('getBusyIntervals');
        $booking = new MsGraphBooking($client, $this->createMock(EntityManagerInterface::class), $this->createMock(FeedSourceRepository::class), new ArrayAdapter(), new ArrayAdapter(), new NullLogger());
        $this->expectException(NotAcceptableException::class);
        $booking->performAction($this->tenant, $this->slide, $this->options());
    }

    private function options(): InteractionSlideRequest
    {
        return new InteractionSlideRequest(InstantBook::class, 'ACTION_GET_QUICK_BOOK_OPTIONS', ['resource' => self::ROOM]);
    }

    private function book(): InteractionSlideRequest
    {
        return new InteractionSlideRequest(InstantBook::class, 'ACTION_QUICK_BOOK', [
            'interval' => ['resource' => self::ROOM, 'durationMinutes' => 30],
            'transactionId' => Ulid::generate(),
        ]);
    }
}
