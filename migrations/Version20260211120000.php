<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260211120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create manual_records table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('manual_records');
        $table->addColumn('id', Types::STRING, ['length' => 5]);
        $table->addColumn('payload_json', Types::TEXT);
        $table->addColumn('valid_until', Types::DATETIME_IMMUTABLE);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('manual_records');
    }
}
