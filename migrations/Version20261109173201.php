<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109173201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the album covers downloaded from TheAudioDB';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album ADD downloaded_cover VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE album ADD cover_looked_up_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album DROP downloaded_cover');
        $this->addSql('ALTER TABLE album DROP cover_looked_up_at');
    }
}
