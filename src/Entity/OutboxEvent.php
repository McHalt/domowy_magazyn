<?php

namespace App\Entity;

use App\Repository\OutboxEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Zdarzenie do huba czekające na wysyłkę; `body` to gotowe bajty żądania, więc każda próba podpisuje to samo. */
#[ORM\Entity(repositoryClass: OutboxEventRepository::class)]
#[ORM\Table(name: 'hub_outbox')]
#[ORM\Index(name: 'idx_hub_outbox_due', columns: ['status', 'next_attempt_at'])]
class OutboxEvent
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 10)]
    private string $status = self::PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $nextAttemptAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastError = null;

    public function __construct(string $id, string $body, ?\DateTimeImmutable $now = null)
    {
        $this->id = $id;
        $this->body = $body;
        $this->createdAt = $this->nextAttemptAt = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string { return $this->id; }
    public function getBody(): string { return $this->body; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getNextAttemptAt(): \DateTimeImmutable { return $this->nextAttemptAt; }
    public function getLastError(): ?string { return $this->lastError; }

    public function markSent(): void
    {
        $this->status = self::SENT;
        $this->attempts++;
        $this->lastError = null;
    }

    public function markFailed(string $error): void
    {
        $this->status = self::FAILED;
        $this->attempts++;
        $this->lastError = mb_substr($error, 0, 255);
    }

    public function scheduleRetry(\DateTimeImmutable $at, string $error): void
    {
        $this->attempts++;
        $this->nextAttemptAt = $at;
        $this->lastError = mb_substr($error, 0, 255);
    }
}
