<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113144816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ReplayGain tags of each track (filled by the next full scan: app:library:scan --full)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE track ADD replay_gain_track_gain DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE track ADD replay_gain_track_peak DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE track ADD replay_gain_album_gain DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE track ADD replay_gain_album_peak DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE track DROP replay_gain_track_gain');
        $this->addSql('ALTER TABLE track DROP replay_gain_track_peak');
        $this->addSql('ALTER TABLE track DROP replay_gain_album_gain');
        $this->addSql('ALTER TABLE track DROP replay_gain_album_peak');
    }
}
