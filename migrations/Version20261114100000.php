<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'When each album was added to the library, read from its id (a UUIDv7 holds the moment it was made)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album ADD added_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // The first 48 bits of a UUIDv7 are the Unix time in milliseconds at which it was made: when a scan created
        // the album. Any other id (none is expected) gets the time of this migration.
        $this->addSql(<<<'SQL'
            UPDATE album SET added_at = CASE
                WHEN substr(id::text, 15, 1) = '7'
                    THEN to_timestamp((('x' || replace(substr(id::text, 1, 13), '-', ''))::bit(48)::bigint) / 1000.0) AT TIME ZONE 'UTC'
                ELSE now() AT TIME ZONE 'UTC'
            END
            SQL);
        $this->addSql('ALTER TABLE album ALTER added_at SET NOT NULL');
        $this->addSql('CREATE INDEX album_added_at ON album (added_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX album_added_at');
        $this->addSql('ALTER TABLE album DROP added_at');
    }
}
