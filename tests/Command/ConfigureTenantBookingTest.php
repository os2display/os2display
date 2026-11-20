<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Tenant\ConfigureTenantCommand;
use App\Entity\Tenant;
use App\Entity\Tenant\InteractiveSlideConfig;
use App\InteractiveSlide\InstantBook;
use App\InteractiveSlide\MsGraphBooking;
use App\Repository\InteractiveSlideRepository;
use App\Repository\TenantRepository;
use App\Service\FeedService;
use App\Service\InteractiveSlideService;
use App\Service\KeyVaultService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;

class ConfigureTenantBookingTest extends TestCase
{
    /**
     * @dataProvider existingConfigurationProvider
     */
    public function testMsGraphActivationNeverOverwritesCalendarApiConfiguration(bool $hasExistingConfig): void
    {
        $tenant = new Tenant();
        $tenant->setTenantKey('test');
        $calendarApiConfiguration = ['username' => 'existing-user-key', 'password' => 'existing-password-key'];
        $existing = $hasExistingConfig ? (new InteractiveSlideConfig())->setConfiguration($calendarApiConfiguration) : null;
        $repo = $this->createMock(InteractiveSlideRepository::class);
        $repo->method('findOneBy')->willReturn($existing);
        $manager = $this->createMock(EntityManagerInterface::class);
        $created = null;
        if ($hasExistingConfig) {
            $manager->expects($this->never())->method('persist');
        } else {
            $manager->expects($this->once())->method('persist')->with($this->callback(
                static fn (InteractiveSlideConfig $config): bool => $config->getTenant() === $tenant
                    && InstantBook::class === $config->getImplementationClass(),
            ))->willReturnCallback(static function (InteractiveSlideConfig $config) use (&$created): void {
                $created = $config;
            });
        }
        $implementations = new \ArrayObject();
        $interactives = new InteractiveSlideService($implementations, $repo, $manager);
        $implementations->append(new InstantBook(
            $interactives, new MockHttpClient(), new KeyVaultService(KeyVaultService::ENVIRONMENT, []),
            new ArrayAdapter(), $this->createMock(FeedService::class), InstantBook::SOURCE_GRAPH,
            new NullLogger(), $this->createMock(MsGraphBooking::class),
        ));
        $tenants = $this->createMock(TenantRepository::class);
        $tenants->method('findAll')->willReturn([$tenant]);
        $tenants->method('findOneBy')->willReturn($tenant);
        $command = new ConfigureTenantCommand($manager, $tenants, $interactives);
        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($command);
        $tester->setInputs(['test', 'n', 'y', 'y', 'y']);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        if (null !== $existing) {
            $this->assertSame($calendarApiConfiguration, $existing->getConfiguration());
        } else {
            $this->assertInstanceOf(InteractiveSlideConfig::class, $created);
            $this->assertSame([], $created->getConfiguration());
        }
        $this->assertStringNotContainsString('Enter password', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function existingConfigurationProvider(): iterable
    {
        yield 'new tenant' => [false];
        yield 'existing Calendar API booking tenant' => [true];
    }
}
