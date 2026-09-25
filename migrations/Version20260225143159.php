<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed domyślnych cech (features) dla lokalnego środowiska dev.
 * Na produkcji INSERT IGNORE nie nadpisze istniejących wierszy.
 */
final class Version20260225143159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed domyślnych cech produktów (name, producer, capacity, flavor, type)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO features (name, name_pl) VALUES
            ('name',           'nazwa'),
            ('producer',       'producent'),
            ('qty in package', 'ilość w opakowaniu'),
            ('unit',           'jednostka')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM features WHERE name IN ('name','producer','qty in package','unit')");
    }
}
