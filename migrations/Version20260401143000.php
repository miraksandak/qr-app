<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image asset storage and PMS configuration to hotel settings';
    }

    public function up(Schema $schema): void
    {
        $assets = $schema->createTable('image_assets');
        $assets->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $assets->addColumn('uuid', Types::STRING, ['length' => 36]);
        $assets->addColumn('hotel_id', Types::INTEGER);
        $assets->addColumn('mime_type', Types::STRING, ['length' => 128]);
        $assets->addColumn('data', Types::BLOB);
        $assets->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $assets->setPrimaryKey(['id']);
        $assets->addUniqueIndex(['uuid'], 'uniq_image_assets_uuid');
        $assets->addIndex(['hotel_id'], 'idx_image_assets_hotel_id');
        $assets->addForeignKeyConstraint('hotels', ['hotel_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_image_assets_hotel_id');

        $this->addSql('ALTER TABLE hotel_configurations ADD logo_image_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE hotel_configurations ADD pms_provider VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE hotel_configurations ADD pms_credential_fields JSON DEFAULT NULL');
        $this->addSql("UPDATE hotel_configurations SET pms_credential_fields = JSON_ARRAY('roomNumber', 'surname') WHERE pms_credential_fields IS NULL");
        $this->addSql('ALTER TABLE hotel_configurations MODIFY pms_credential_fields JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hotel_configurations DROP COLUMN logo_image_uuid');
        $this->addSql('ALTER TABLE hotel_configurations DROP COLUMN pms_provider');
        $this->addSql('ALTER TABLE hotel_configurations DROP COLUMN pms_credential_fields');

        $schema->dropTable('image_assets');
    }
}
