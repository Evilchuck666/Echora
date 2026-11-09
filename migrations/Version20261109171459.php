<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109171459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store where the image of each artist comes from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artist ADD image_path TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE artist ADD downloaded_image VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE artist ADD image_looked_up_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artist DROP image_path');
        $this->addSql('ALTER TABLE artist DROP downloaded_image');
        $this->addSql('ALTER TABLE artist DROP image_looked_up_at');
    }
}
