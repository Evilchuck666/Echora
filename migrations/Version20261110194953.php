<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110194953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Playlists of each user, and their items (tracks in order, duplicates allowed)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE playlist (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(100) NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX playlist_owner_updated ON playlist (owner_id, updated_at)');
        $this->addSql('CREATE INDEX IDX_D782112D7E3C61F9 ON playlist (owner_id)');
        $this->addSql('CREATE TABLE playlist_item (id UUID NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sort_key BIGINT NOT NULL, playlist_id UUID NOT NULL, track_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX playlist_item_sort_key ON playlist_item (playlist_id, sort_key)');
        $this->addSql('CREATE INDEX IDX_BF02127C6BBD148 ON playlist_item (playlist_id)');
        $this->addSql('CREATE INDEX IDX_BF02127C5ED23C43 ON playlist_item (track_id)');
        $this->addSql('ALTER TABLE playlist ADD CONSTRAINT FK_D782112D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT FK_BF02127C6BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT FK_BF02127C5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE');
        // App\Playlists\PlaylistEditor counts on positive keys (Doctrine doesn't map CHECK constraints).
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT playlist_item_positive_sort_key CHECK (sort_key > 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist DROP CONSTRAINT FK_D782112D7E3C61F9');
        $this->addSql('ALTER TABLE playlist_item DROP CONSTRAINT FK_BF02127C6BBD148');
        $this->addSql('ALTER TABLE playlist_item DROP CONSTRAINT FK_BF02127C5ED23C43');
        $this->addSql('DROP TABLE playlist_item');
        $this->addSql('DROP TABLE playlist');
    }
}
