<?php

namespace App\Command;

use App\Hub\Events\HubScan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:hub:scan',
    description: 'Skanuje ważność i stany minimalne i zapisuje zdarzenia dla huba do kolejki (raz dziennie)',
)]
final class HubScanCommand extends Command
{
    public function __construct(private readonly HubScan $scan)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->scan->run(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        if (!$result['ran']) {
            $output->writeln('Skan już trwa, pomijam.');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('Zdarzenia zapisane do kolejki: ważność (%d poz.), stany minimalne (%d poz.).', $result['expiring'], $result['stockLow']));

        return Command::SUCCESS;
    }
}
