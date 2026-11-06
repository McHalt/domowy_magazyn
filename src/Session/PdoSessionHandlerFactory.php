<?php

namespace App\Session;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class PdoSessionHandlerFactory
{
    public function __construct(private readonly Connection $connection) {}

    public function create(): PdoSessionHandler
    {
        return new PdoSessionHandler($this->connection->getNativeConnection(), [
            'db_table' => 'sessions',
            'lock_mode' => PdoSessionHandler::LOCK_ADVISORY,
        ]);
    }
}
