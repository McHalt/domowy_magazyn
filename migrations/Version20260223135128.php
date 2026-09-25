<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260223135128 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE features (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, name_pl VARCHAR(200) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE min_stock_rules (id INT AUTO_INCREMENT NOT NULL, min_stock INT NOT NULL, min_stock_basis VARCHAR(255) NOT NULL, product_id INT DEFAULT NULL, group_id INT DEFAULT NULL, INDEX IDX_8F9F63D34584665A (product_id), INDEX IDX_8F9F63D3FE54D947 (group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE products (id INT AUTO_INCREMENT NOT NULL, ean BIGINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE products_to_products_groups (product_id INT NOT NULL, products_group_id INT NOT NULL, INDEX IDX_C7CF37874584665A (product_id), INDEX IDX_C7CF3787EB55C9F4 (products_group_id), PRIMARY KEY (product_id, products_group_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE products_groups (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE products_history (id INT AUTO_INCREMENT NOT NULL, cost INT NOT NULL, active INT NOT NULL, date_added DATE NOT NULL, expiration_date DATE DEFAULT NULL, products_id INT NOT NULL, INDEX IDX_FC878A166C8A81A9 (products_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE products_to_features (value VARCHAR(255) NOT NULL, products_id INT NOT NULL, features_id INT NOT NULL, INDEX IDX_884420266C8A81A9 (products_id), INDEX IDX_88442026CEC89005 (features_id), PRIMARY KEY (products_id, features_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE min_stock_rules ADD CONSTRAINT FK_8F9F63D34584665A FOREIGN KEY (product_id) REFERENCES products (id)');
        $this->addSql('ALTER TABLE min_stock_rules ADD CONSTRAINT FK_8F9F63D3FE54D947 FOREIGN KEY (group_id) REFERENCES products_groups (id)');
        $this->addSql('ALTER TABLE products_to_products_groups ADD CONSTRAINT FK_C7CF37874584665A FOREIGN KEY (product_id) REFERENCES products (id)');
        $this->addSql('ALTER TABLE products_to_products_groups ADD CONSTRAINT FK_C7CF3787EB55C9F4 FOREIGN KEY (products_group_id) REFERENCES products_groups (id)');
        $this->addSql('ALTER TABLE products_history ADD CONSTRAINT FK_FC878A166C8A81A9 FOREIGN KEY (products_id) REFERENCES products (id)');
        $this->addSql('ALTER TABLE products_to_features ADD CONSTRAINT FK_884420266C8A81A9 FOREIGN KEY (products_id) REFERENCES products (id)');
        $this->addSql('ALTER TABLE products_to_features ADD CONSTRAINT FK_88442026CEC89005 FOREIGN KEY (features_id) REFERENCES features (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE min_stock_rules DROP FOREIGN KEY FK_8F9F63D34584665A');
        $this->addSql('ALTER TABLE min_stock_rules DROP FOREIGN KEY FK_8F9F63D3FE54D947');
        $this->addSql('ALTER TABLE products_to_products_groups DROP FOREIGN KEY FK_C7CF37874584665A');
        $this->addSql('ALTER TABLE products_to_products_groups DROP FOREIGN KEY FK_C7CF3787EB55C9F4');
        $this->addSql('ALTER TABLE products_history DROP FOREIGN KEY FK_FC878A166C8A81A9');
        $this->addSql('ALTER TABLE products_to_features DROP FOREIGN KEY FK_884420266C8A81A9');
        $this->addSql('ALTER TABLE products_to_features DROP FOREIGN KEY FK_88442026CEC89005');
        $this->addSql('DROP TABLE features');
        $this->addSql('DROP TABLE min_stock_rules');
        $this->addSql('DROP TABLE products');
        $this->addSql('DROP TABLE products_to_products_groups');
        $this->addSql('DROP TABLE products_groups');
        $this->addSql('DROP TABLE products_history');
        $this->addSql('DROP TABLE products_to_features');
    }
}
