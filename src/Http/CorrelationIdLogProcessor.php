<?php

namespace App\Http;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

#[AsMonologProcessor]
final class CorrelationIdLogProcessor implements ProcessorInterface
{
    public function __construct(private readonly CorrelationId $correlationId) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $id = $this->correlationId->current();

        return $id === null ? $record : $record->with(extra: [...$record->extra, 'correlationId' => $id]);
    }
}
