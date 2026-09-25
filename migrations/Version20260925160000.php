<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jednostka grupy produktów (products_groups.unit) na potrzeby stanów minimalnych';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products_groups ADD unit VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products_groups DROP unit');
    }
}
