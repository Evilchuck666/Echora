<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109144608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store album covers and track lyrics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album ADD cover_path TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE track ADD lyrics TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE album DROP cover_path');
        $this->addSql('ALTER TABLE track DROP lyrics');
    }
}
