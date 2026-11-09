<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109123857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the music library: artist, album and track';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE album (id UUID NOT NULL, title VARCHAR(255) NOT NULL, year INT DEFAULT NULL, artist_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX album_artist_title ON album (artist_id, title)');
        $this->addSql('CREATE INDEX IDX_39986E43B7970CF8 ON album (artist_id)');
        $this->addSql('CREATE TABLE artist (id UUID NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_15996875E237E06 ON artist (name)');
        $this->addSql('CREATE TABLE track (id UUID NOT NULL, title VARCHAR(255) NOT NULL, track_number INT DEFAULT NULL, disc_number INT DEFAULT NULL, duration_ms INT DEFAULT NULL, genre VARCHAR(255) DEFAULT NULL, format VARCHAR(16) NOT NULL, mime_type VARCHAR(64) NOT NULL, file_size BIGINT NOT NULL, file_modified_at BIGINT NOT NULL, path TEXT NOT NULL, album_id UUID NOT NULL, artist_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D6E3F8A6B548B0F ON track (path)');
        $this->addSql('CREATE INDEX IDX_D6E3F8A61137ABCF ON track (album_id)');
        $this->addSql('CREATE INDEX IDX_D6E3F8A6B7970CF8 ON track (artist_id)');
        $this->addSql('ALTER TABLE album ADD CONSTRAINT FK_39986E43B7970CF8 FOREIGN KEY (artist_id) REFERENCES artist (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT FK_D6E3F8A61137ABCF FOREIGN KEY (album_id) REFERENCES album (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT FK_D6E3F8A6B7970CF8 FOREIGN KEY (artist_id) REFERENCES artist (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album DROP CONSTRAINT FK_39986E43B7970CF8');
        $this->addSql('ALTER TABLE track DROP CONSTRAINT FK_D6E3F8A61137ABCF');
        $this->addSql('ALTER TABLE track DROP CONSTRAINT FK_D6E3F8A6B7970CF8');
        $this->addSql('DROP TABLE album');
        $this->addSql('DROP TABLE artist');
        $this->addSql('DROP TABLE track');
    }
}
