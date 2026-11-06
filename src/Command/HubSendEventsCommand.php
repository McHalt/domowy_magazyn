<?php

namespace App\Command;

use App\Hub\Events\EventSender;
use App\Repository\OutboxEventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:hub:send-events',
    description: 'Wysyła zaległe zdarzenia z kolejki do huba (co minutę)',
)]
final class HubSendEventsCommand extends Command
{
    private const KEEP_FINISHED_DAYS = 30;

    public function __construct(
        private readonly EventSender $sender,
        private readonly OutboxEventRepository $outbox,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->locks->createLock('hub-send-events', 120);
        if (!$lock->acquire()) {
            $output->writeln('Wysyłka już trwa, pomijam.');

            return Command::SUCCESS;
        }

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $result = $this->sender->deliverDue($now);
            $this->outbox->deleteFinishedBefore($now->modify(sprintf('-%d days', self::KEEP_FINISHED_DAYS)));
        } finally {
            $lock->release();
        }

        if (array_sum($result) > 0) {
            $output->writeln(sprintf('Wysłane: %d, do ponowienia: %d, odrzucone: %d.', $result['sent'], $result['retry'], $result['failed']));
        }

        return Command::SUCCESS;
    }
}
