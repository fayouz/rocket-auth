<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926001606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Back-channel logout: endpoint of the applications, Rocket Auth session (sid) of the sign-ins';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE authorization_code ADD sid VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE oauth_client ADD backchannel_logout_uri VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE refresh_token ADD sid VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE authorization_code DROP sid');
        $this->addSql('ALTER TABLE oauth_client DROP backchannel_logout_uri');
        $this->addSql('ALTER TABLE refresh_token DROP sid');
    }
}
