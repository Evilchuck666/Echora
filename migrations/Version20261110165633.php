<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110165633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Favourite tracks, albums and artists of each user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE favourite (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, track_id UUID DEFAULT NULL, album_id UUID DEFAULT NULL, artist_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX favourite_user_created ON favourite (user_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX favourite_user_track ON favourite (user_id, track_id)');
        $this->addSql('CREATE UNIQUE INDEX favourite_user_album ON favourite (user_id, album_id)');
        $this->addSql('CREATE UNIQUE INDEX favourite_user_artist ON favourite (user_id, artist_id)');
        $this->addSql('CREATE INDEX IDX_62A2CA19A76ED395 ON favourite (user_id)');
        $this->addSql('CREATE INDEX IDX_62A2CA195ED23C43 ON favourite (track_id)');
        $this->addSql('CREATE INDEX IDX_62A2CA191137ABCF ON favourite (album_id)');
        $this->addSql('CREATE INDEX IDX_62A2CA19B7970CF8 ON favourite (artist_id)');
        $this->addSql('ALTER TABLE favourite ADD CONSTRAINT FK_62A2CA19A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE favourite ADD CONSTRAINT FK_62A2CA195ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE favourite ADD CONSTRAINT FK_62A2CA191137ABCF FOREIGN KEY (album_id) REFERENCES album (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE favourite ADD CONSTRAINT FK_62A2CA19B7970CF8 FOREIGN KEY (artist_id) REFERENCES artist (id) ON DELETE CASCADE NOT DEFERRABLE');
        // A favourite is of exactly one track, album or artist (Doctrine doesn't map CHECK constraints).
        $this->addSql('ALTER TABLE favourite ADD CONSTRAINT favourite_one_item CHECK (num_nonnulls(track_id, album_id, artist_id) = 1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE favourite DROP CONSTRAINT FK_62A2CA19A76ED395');
        $this->addSql('ALTER TABLE favourite DROP CONSTRAINT FK_62A2CA195ED23C43');
        $this->addSql('ALTER TABLE favourite DROP CONSTRAINT FK_62A2CA191137ABCF');
        $this->addSql('ALTER TABLE favourite DROP CONSTRAINT FK_62A2CA19B7970CF8');
        $this->addSql('DROP TABLE favourite');
    }
}
