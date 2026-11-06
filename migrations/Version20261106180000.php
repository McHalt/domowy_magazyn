<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Integracja z hubem: domownicy, sesje, kolejka zdarzeń, stan alertów i autor wpisów historii';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE members (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sessions (sess_id VARBINARY(128) NOT NULL PRIMARY KEY, sess_data BLOB NOT NULL, sess_lifetime INTEGER UNSIGNED NOT NULL, sess_time INTEGER UNSIGNED NOT NULL, INDEX sessions_sess_lifetime_idx (sess_lifetime)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin ENGINE = InnoDB');
        $this->addSql('CREATE TABLE hub_outbox (id VARCHAR(64) NOT NULL, body TEXT NOT NULL, status VARCHAR(10) NOT NULL, attempts INT NOT NULL, created_at DATETIME NOT NULL, next_attempt_at DATETIME NOT NULL, last_error VARCHAR(255) DEFAULT NULL, INDEX idx_hub_outbox_due (status, next_attempt_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE hub_alert_state (alert_key VARCHAR(64) NOT NULL, since DATETIME NOT NULL, PRIMARY KEY (alert_key)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE products_history ADD added_by VARCHAR(36) DEFAULT NULL, ADD removed_by VARCHAR(36) DEFAULT NULL, ADD removed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products_history DROP added_by, DROP removed_by, DROP removed_at');
        $this->addSql('DROP TABLE hub_alert_state');
        $this->addSql('DROP TABLE hub_outbox');
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE members');
    }
}
