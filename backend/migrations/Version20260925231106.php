<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925231106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suite: address and icon of the applications in the switcher';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth_client ADD home_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE oauth_client ADD icon VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth_client DROP home_url');
        $this->addSql('ALTER TABLE oauth_client DROP icon');
    }
}
