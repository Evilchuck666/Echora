<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113134522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'History of library scans, asked for through the API, from the console or by the nightly job, with their progress and reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE library_scan (id UUID NOT NULL, status VARCHAR(16) NOT NULL, phase VARCHAR(16) DEFAULT NULL, phase_done INT DEFAULT NULL, phase_total INT DEFAULT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, added INT DEFAULT NULL, moved INT DEFAULT NULL, updated INT DEFAULT NULL, unchanged INT DEFAULT NULL, removed INT DEFAULT NULL, failed INT DEFAULT NULL, failures JSON DEFAULT NULL, covers_updated INT DEFAULT NULL, covers_downloaded INT DEFAULT NULL, artist_images_updated INT DEFAULT NULL, artist_images_downloaded INT DEFAULT NULL, the_audio_db_failure TEXT DEFAULT NULL, error TEXT DEFAULT NULL, full_scan BOOLEAN NOT NULL, trigger VARCHAR(16) NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE library_scan');
    }
}
