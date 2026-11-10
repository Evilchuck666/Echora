<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110164415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hash of the audio of each track, to keep a track when its file is moved or renamed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE track ADD audio_hash VARCHAR(48) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE track DROP audio_hash');
    }
}
