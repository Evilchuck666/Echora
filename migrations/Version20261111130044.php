<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111130044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accounts get an email, a name and a picture; sessions name them by id instead of username, so renaming keeps them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD email VARCHAR(180) DEFAULT NULL');
        // Existing accounts are shown by their username until they choose a name.
        $this->addSql('ALTER TABLE app_user ADD name VARCHAR(100) DEFAULT NULL');
        $this->addSql('UPDATE app_user SET name = username');
        $this->addSql('ALTER TABLE app_user ALTER name SET NOT NULL');
        $this->addSql('ALTER TABLE app_user ADD avatar VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        // Refresh tokens name their account like JWTs now (User::getUserIdentifier()): by id. Sessions stay open.
        $this->addSql('UPDATE refresh_tokens r SET username = u.id::text FROM app_user u WHERE r.username = u.username');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE refresh_tokens r SET username = u.username FROM app_user u WHERE r.username = u.id::text');
        $this->addSql('DROP INDEX UNIQ_88BDF3E9E7927C74');
        $this->addSql('ALTER TABLE app_user DROP email');
        $this->addSql('ALTER TABLE app_user DROP name');
        $this->addSql('ALTER TABLE app_user DROP avatar');
    }
}
