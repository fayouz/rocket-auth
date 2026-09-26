<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925175802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OpenID Connect provider: OAuth clients, authorization codes, refresh tokens, consents, sign-in events and user groups';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE authorization_code (id UUID NOT NULL, code_hash VARCHAR(64) NOT NULL, redirect_uri TEXT NOT NULL, scopes JSON NOT NULL, nonce VARCHAR(255) DEFAULT NULL, code_challenge VARCHAR(128) DEFAULT NULL, auth_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, client_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2F33E8B8E7530879 ON authorization_code (code_hash)');
        $this->addSql('CREATE INDEX idx_authorization_code_expires ON authorization_code (expires_at)');
        $this->addSql('CREATE INDEX IDX_2F33E8B819EB6921 ON authorization_code (client_id)');
        $this->addSql('CREATE INDEX IDX_2F33E8B8A76ED395 ON authorization_code (user_id)');
        $this->addSql('CREATE TABLE consent (id UUID NOT NULL, scopes JSON NOT NULL, granted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, client_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_consent_user_client ON consent (user_id, client_id)');
        $this->addSql('CREATE INDEX IDX_63120810A76ED395 ON consent (user_id)');
        $this->addSql('CREATE INDEX IDX_6312081019EB6921 ON consent (client_id)');
        $this->addSql('CREATE TABLE oauth_client (id UUID NOT NULL, name VARCHAR(120) NOT NULL, description TEXT DEFAULT NULL, client_id VARCHAR(80) NOT NULL, secret_hash VARCHAR(64) DEFAULT NULL, secret_hint VARCHAR(16) DEFAULT NULL, confidential BOOLEAN NOT NULL, redirect_uris JSON NOT NULL, post_logout_redirect_uris JSON DEFAULT \'[]\' NOT NULL, allowed_scopes JSON NOT NULL, grant_types JSON NOT NULL, trusted BOOLEAN NOT NULL, enabled BOOLEAN NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AD73274D19EB6921 ON oauth_client (client_id)');
        $this->addSql('CREATE TABLE refresh_token (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL, auth_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, client_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C74F2195B3BC57DA ON refresh_token (token_hash)');
        $this->addSql('CREATE INDEX idx_refresh_token_user_client ON refresh_token (user_id, client_id)');
        $this->addSql('CREATE INDEX IDX_C74F219519EB6921 ON refresh_token (client_id)');
        $this->addSql('CREATE INDEX IDX_C74F2195A76ED395 ON refresh_token (user_id)');
        $this->addSql('CREATE TABLE sign_in_event (id UUID NOT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, type VARCHAR(16) NOT NULL, user_id UUID DEFAULT NULL, client_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_sign_in_event_at ON sign_in_event (at)');
        $this->addSql('CREATE INDEX IDX_B2E2F23EA76ED395 ON sign_in_event (user_id)');
        $this->addSql('CREATE INDEX IDX_B2E2F23E19EB6921 ON sign_in_event (client_id)');
        $this->addSql('ALTER TABLE authorization_code ADD CONSTRAINT FK_2F33E8B819EB6921 FOREIGN KEY (client_id) REFERENCES oauth_client (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE authorization_code ADD CONSTRAINT FK_2F33E8B8A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE consent ADD CONSTRAINT FK_63120810A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE consent ADD CONSTRAINT FK_6312081019EB6921 FOREIGN KEY (client_id) REFERENCES oauth_client (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE refresh_token ADD CONSTRAINT FK_C74F219519EB6921 FOREIGN KEY (client_id) REFERENCES oauth_client (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE refresh_token ADD CONSTRAINT FK_C74F2195A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sign_in_event ADD CONSTRAINT FK_B2E2F23EA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sign_in_event ADD CONSTRAINT FK_B2E2F23E19EB6921 FOREIGN KEY (client_id) REFERENCES oauth_client (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE "user" ADD groups JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE authorization_code DROP CONSTRAINT FK_2F33E8B819EB6921');
        $this->addSql('ALTER TABLE authorization_code DROP CONSTRAINT FK_2F33E8B8A76ED395');
        $this->addSql('ALTER TABLE consent DROP CONSTRAINT FK_63120810A76ED395');
        $this->addSql('ALTER TABLE consent DROP CONSTRAINT FK_6312081019EB6921');
        $this->addSql('ALTER TABLE refresh_token DROP CONSTRAINT FK_C74F219519EB6921');
        $this->addSql('ALTER TABLE refresh_token DROP CONSTRAINT FK_C74F2195A76ED395');
        $this->addSql('ALTER TABLE sign_in_event DROP CONSTRAINT FK_B2E2F23EA76ED395');
        $this->addSql('ALTER TABLE sign_in_event DROP CONSTRAINT FK_B2E2F23E19EB6921');
        $this->addSql('DROP TABLE authorization_code');
        $this->addSql('DROP TABLE consent');
        $this->addSql('DROP TABLE oauth_client');
        $this->addSql('DROP TABLE refresh_token');
        $this->addSql('DROP TABLE sign_in_event');
        $this->addSql('ALTER TABLE "user" DROP groups');
    }
}
