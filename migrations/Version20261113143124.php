<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113143124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Listening history: the plays of each user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE play (id UUID NOT NULL, played_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, track_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX play_user_played ON play (user_id, played_at)');
        $this->addSql('CREATE INDEX play_user_track ON play (user_id, track_id)');
        $this->addSql('CREATE INDEX IDX_5E89DEBAA76ED395 ON play (user_id)');
        $this->addSql('CREATE INDEX IDX_5E89DEBA5ED23C43 ON play (track_id)');
        $this->addSql('ALTER TABLE play ADD CONSTRAINT FK_5E89DEBAA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE play ADD CONSTRAINT FK_5E89DEBA5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play DROP CONSTRAINT FK_5E89DEBAA76ED395');
        $this->addSql('ALTER TABLE play DROP CONSTRAINT FK_5E89DEBA5ED23C43');
        $this->addSql('DROP TABLE play');
    }
}
