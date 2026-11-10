<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110205531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Each user orders their playlists by hand: existing playlists keep their order (last changed first, then by id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist ADD sort_key BIGINT DEFAULT NULL');
        // The order they were listed in until now, with gaps of App\Ordering\SortKeys::STEP (2^20).
        $this->addSql('UPDATE playlist p SET sort_key = o.n * 1048576 FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY owner_id ORDER BY updated_at DESC, id DESC) AS n FROM playlist) o WHERE p.id = o.id');
        $this->addSql('ALTER TABLE playlist ALTER sort_key SET NOT NULL');
        $this->addSql('DROP INDEX playlist_owner_updated');
        $this->addSql('CREATE UNIQUE INDEX playlist_owner_sort_key ON playlist (owner_id, sort_key)');
        // App\Ordering\SortKeys counts on positive keys (Doctrine doesn't map CHECK constraints).
        $this->addSql('ALTER TABLE playlist ADD CONSTRAINT playlist_positive_sort_key CHECK (sort_key > 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist DROP CONSTRAINT playlist_positive_sort_key');
        $this->addSql('DROP INDEX playlist_owner_sort_key');
        $this->addSql('ALTER TABLE playlist DROP sort_key');
        $this->addSql('CREATE INDEX playlist_owner_updated ON playlist (owner_id, updated_at)');
    }
}
