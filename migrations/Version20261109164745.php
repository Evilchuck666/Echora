<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109164745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable unaccent, for searches that ignore accents';
    }

    public function up(Schema $schema): void
    {
        // Ships with PostgreSQL (contrib). Used by the UNACCENT() DQL function (App\Doctrine\Unaccent).
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS unaccent');
    }
}
