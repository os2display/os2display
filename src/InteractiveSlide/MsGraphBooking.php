<?php

declare(strict_types=1);

namespace App\InteractiveSlide;

use App\Entity\Tenant;
use App\Entity\Tenant\FeedSource;
use App\Entity\Tenant\Slide;
use App\Exceptions\BadRequestException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotAcceptableException;
use App\Exceptions\TooManyRequestsException;
use App\Feed\MsGraphFeedType;
use App\Feed\SourceType\MsGraph\ApiClient;
use App\Feed\SourceType\MsGraph\MsGraphException;
use App\Repository\FeedSourceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Cache\CacheInterface;

class MsGraphBooking
{
    private const array DURATIONS = [15, 30, 60];
    private const int REQUEST_TTL = 86400;

    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly FeedSourceRepository $feedSourceRepository,
        private readonly CacheItemPoolInterface $interactiveSlideCache,
        private readonly CacheInterface $feedsCache,
        private readonly LoggerInterface $interactiveLogger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function performAction(Tenant $tenant, Slide $slide, InteractionSlideRequest $request): array
    {
        try {
            return match ($request->action) {
                'ACTION_GET_QUICK_BOOK_OPTIONS' => $this->getOptions($tenant, $slide, $request),
                'ACTION_QUICK_BOOK' => $this->book($tenant, $slide, $request),
                default => throw new NotAcceptableException('Action not supported'),
            };
        } catch (MsGraphException $exception) {
            $this->interactiveLogger->error('MS Graph instant booking failed.', [
                'slide_id' => (string) $slide->getId(),
                'status' => $exception->getCode(),
            ]);

            throw match ($exception->getCode()) {
                409 => new ConflictException('Interval booked already'),
                429 => new TooManyRequestsException('Microsoft Graph is temporarily throttling booking requests.'),
                default => new HttpException(502, 'Microsoft Graph booking could not be completed. Retry the same booking if the result is uncertain.'),
            };
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getOptions(Tenant $tenant, Slide $slide, InteractionSlideRequest $request): array
    {
        $resource = $request->data['resource'] ?? null;
        $source = $this->validateResource($tenant, $slide, $resource);
        $from = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $busy = $this->apiClient->getBusyIntervals($source, $resource, $from, $from->modify('+60 minutes'));
        $options = [];

        foreach (self::DURATIONS as $duration) {
            $to = $from->modify('+'.$duration.' minutes');
            if ($this->intervalFree($busy, $from, $to)) {
                $options[] = ['durationMinutes' => $duration, 'to' => $to->format('c')];
            }
        }

        return ['resource' => $resource, 'from' => $from->format('c'), 'options' => $options];
    }

    /**
     * @return array<string, mixed>
     */
    private function book(Tenant $tenant, Slide $slide, InteractionSlideRequest $request): array
    {
        $interval = $request->data['interval'] ?? [];
        $resource = $interval['resource'] ?? null;
        $source = $this->validateResource($tenant, $slide, $resource);
        $duration = $interval['durationMinutes'] ?? null;
        $requestId = $request->data['transactionId'] ?? null;
        if (!in_array($duration, self::DURATIONS, true) || !is_string($requestId) || !Ulid::isValid($requestId)) {
            throw new BadRequestException('A supported duration and a ULID transactionId are required.');
        }

        // A database row lock works across PHP workers and hosts, irrespective of the cache adapter.
        // Serialize per tenant so different feed sources using the same room cannot race each other.
        return $this->entityManager->getConnection()->transactional(function () use ($tenant, $slide, $source, $resource, $duration, $requestId): array {
            $this->entityManager->lock($tenant, LockMode::PESSIMISTIC_WRITE);
            $transactionId = hash('sha256', $tenant->getId().'-'.$slide->getId().'-'.$requestId);
            $key = 'msgraph-booking-'.$transactionId;
            $item = $this->interactiveSlideCache->getItem($key);
            $attempt = $item->isHit() ? $item->get() : null;
            $fresh = null === $attempt;

            if (null !== $attempt && ($attempt['resource'] !== $resource || $attempt['duration'] !== $duration || $attempt['source'] !== (string) $source->getId())) {
                throw new BadRequestException('transactionId already belongs to a different booking.');
            }

            if (null === $attempt) {
                $rateKey = 'msgraph-booking-rate-'.hash('sha256', $tenant->getId().'-'.strtolower($resource));
                if ($this->interactiveSlideCache->hasItem($rateKey)) {
                    throw new TooManyRequestsException('Please wait before booking this room again.');
                }

                $from = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $to = $from->modify('+'.$duration.' minutes');
                $busy = $this->apiClient->getBusyIntervals($source, $resource, $from, $to);
                if (!$this->intervalFree($busy, $from, $to)) {
                    throw new ConflictException('Interval booked already');
                }

                $attempt = [
                    'resource' => $resource,
                    'source' => (string) $source->getId(),
                    'duration' => $duration,
                    'from' => $from->format('c'),
                    'to' => $to->format('c'),
                ];
                $item->set($attempt)->expiresAfter(self::REQUEST_TTL);
                if (!$this->interactiveSlideCache->save($item)) {
                    throw new \RuntimeException('Could not persist the booking request before contacting Graph.');
                }
                $rate = $this->interactiveSlideCache->getItem($rateKey);
                $rate->set(true)->expiresAfter(60);
                if (!$this->interactiveSlideCache->save($rate)) {
                    throw new \RuntimeException('Could not persist the booking rate limit.');
                }
            }

            if (!isset($attempt['result'])) {
                $from = new \DateTimeImmutable($attempt['from']);
                $to = new \DateTimeImmutable($attempt['to']);
                if ($to <= new \DateTimeImmutable()) {
                    throw new NotAcceptableException('The booking interval has expired. Check the calendar before trying again.');
                }

                // Retry the exact payload and transactionId after an ambiguous Graph timeout.
                try {
                    $this->apiClient->createRoomEvent($source, $resource, $from, $to, $transactionId);
                } catch (MsGraphException $exception) {
                    // A definite first-attempt rejection must recheck availability on retry.
                    // Keep ambiguous attempts: Graph may already have created their event.
                    if ($fresh && $exception->getCode() >= 400 && $exception->getCode() < 500) {
                        $this->interactiveSlideCache->deleteItem($key);
                    }

                    throw $exception;
                }
                $attempt['result'] = ['status' => 201, 'interval' => ['from' => $attempt['from'], 'to' => $attempt['to']]];
                $item->set($attempt)->expiresAfter(self::REQUEST_TTL);
                if (!$this->interactiveSlideCache->save($item)) {
                    throw new \RuntimeException('Could not persist the confirmed booking result.');
                }
            }

            $this->invalidateRoom($tenant, $source, $resource);

            return $attempt['result'];
        });
    }

    private function validateResource(Tenant $tenant, Slide $slide, mixed $resource): FeedSource
    {
        $feed = $slide->getFeed();
        $source = $feed?->getFeedSource();
        $content = $slide->getContent();

        if (null === $tenant->getId() || null === $slide->getId() || null === $source?->getId()
            || $slide->getTenant()->getId() != $tenant->getId()
            || $feed->getTenant()->getId() != $tenant->getId()
            || $source->getTenant()->getId() != $tenant->getId()
            || MsGraphFeedType::class !== $source->getFeedType()
            || true !== ($content['instantBookingEnabled'] ?? false)
            || 'singleBooking' !== ($content['layout'] ?? null)
            || !is_string($resource) || '' === $resource
            || [$resource] !== ($feed->getConfiguration()['resources'] ?? [])) {
            throw new NotAcceptableException('Instant booking is not enabled for this slide and room.');
        }

        $rooms = $this->apiClient->getRooms($source);
        if (!isset($rooms[$resource])) {
            throw new NotAcceptableException('The room is not available through this feed source.');
        }

        return $source;
    }

    /**
     * @param list<array{startTime: \DateTimeImmutable, endTime: \DateTimeImmutable}> $busy
     */
    private function intervalFree(array $busy, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return array_all($busy, static fn (array $entry): bool => $entry['startTime'] >= $to || $entry['endTime'] <= $from);
    }

    private function invalidateRoom(Tenant $tenant, FeedSource $source, string $resource): void
    {
        // Different sources in this tenant may use different credentials for the same room.
        $this->apiClient->invalidateRoomEvents($source, $resource);
        $sources = $this->feedSourceRepository->findBy(['tenant' => $tenant, 'feedType' => MsGraphFeedType::class]);
        foreach ($sources as $candidate) {
            $affected = false;
            foreach ($candidate->getFeeds() as $feed) {
                if (in_array($resource, $feed->getConfiguration()['resources'] ?? [], true) && null !== $feed->getId()) {
                    $affected = true;
                    $this->feedsCache->delete((string) $feed->getId());
                }
            }
            if ($affected && $candidate->getId() != $source->getId()) {
                $this->apiClient->invalidateRoomEvents($candidate, $resource);
            }
        }
    }
}
