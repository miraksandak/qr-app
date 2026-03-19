<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create hotels and hotel_configurations tables';
    }

    public function up(Schema $schema): void
    {
        $hotels = $schema->createTable('hotels');
        $hotels->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $hotels->addColumn('external_hotel_id', Types::STRING, ['length' => 128]);
        $hotels->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $hotels->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $hotels->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $hotels->setPrimaryKey(['id']);
        $hotels->addUniqueIndex(['external_hotel_id'], 'uniq_hotels_external_hotel_id');

        $configurations = $schema->createTable('hotel_configurations');
        $configurations->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $configurations->addColumn('hotel_id', Types::INTEGER);
        $configurations->addColumn('support_text', Types::TEXT, ['notnull' => false]);
        $configurations->addColumn('footer_text', Types::TEXT, ['notnull' => false]);
        $configurations->addColumn('logo_url', Types::STRING, ['length' => 2048, 'notnull' => false]);
        $configurations->addColumn('portal_url', Types::STRING, ['length' => 2048, 'notnull' => false]);
        $configurations->addColumn('proxy_api_base_url', Types::STRING, ['length' => 2048, 'notnull' => false]);
        $configurations->addColumn('datacenter_id', Types::STRING, ['length' => 128, 'notnull' => false]);
        $configurations->addColumn('primary_auth_mode', Types::STRING, ['length' => 32]);
        $configurations->addColumn('default_device', Types::STRING, ['length' => 32]);
        $configurations->addColumn('available_devices', Types::JSON);
        $configurations->addColumn('ssids', Types::JSON);
        $configurations->addColumn('upgrade_enabled', Types::BOOLEAN);
        $configurations->addColumn('upgrade_url', Types::STRING, ['length' => 2048, 'notnull' => false]);
        $configurations->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $configurations->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $configurations->setPrimaryKey(['id']);
        $configurations->addUniqueIndex(['hotel_id'], 'uniq_hotel_configurations_hotel_id');
        $configurations->addForeignKeyConstraint('hotels', ['hotel_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_hotel_configurations_hotel_id');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('hotel_configurations');
        $schema->dropTable('hotels');
    }
}
