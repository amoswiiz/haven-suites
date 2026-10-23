<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPartnerBookingsToReservations extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('partner_apartments')) {
            throw new \RuntimeException('The partner_apartments table must exist before this migration runs.');
        }

        $columns = [
            'booking_type' => [
                'type' => 'VARCHAR', 'constraint' => 20,
                'default' => 'managed', 'after' => 'unit_id',
            ],
            'partner_apartment_id' => [
                'type' => 'INT', 'unsigned' => true,
                'null' => true, 'after' => 'booking_type',
            ],
            'owner_net_amount' => [
                'type' => 'DECIMAL', 'constraint' => '12,2',
                'null' => true, 'after' => 'total_amount',
            ],
        ];

        foreach ($columns as $name => $definition) {
            if (! $this->db->fieldExists($name, 'reservations')) {
                $this->forge->addColumn('reservations', [$name => $definition]);
            }
        }

        // Partner reservations have no managed unit. Preserve the existing unit FK.
        $unitColumn = null;
        foreach ($this->db->getFieldData('reservations') as $field) {
            if ($field->name === 'unit_id') {
                $unitColumn = $field;
                break;
            }
        }
        if ($unitColumn === null) {
            throw new \RuntimeException('reservations.unit_id was not found.');
        }
        if (! $unitColumn->nullable) {
            $this->forge->modifyColumn('reservations', [
                'unit_id' => [
                    'name' => 'unit_id', 'type' => 'INT',
                    'unsigned' => true, 'null' => true,
                ],
            ]);
        }

        $index = $this->db->query(
            'SELECT COUNT(*) AS total FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['reservations', 'idx_reservations_partner_apartment_id']
        )->getRow();
        if ((int) $index->total === 0) {
            $this->db->query(
                'CREATE INDEX idx_reservations_partner_apartment_id '
                . 'ON reservations (partner_apartment_id)'
            );
        }

        $foreignKey = $this->db->query(
            'SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . 'AND CONSTRAINT_NAME = ? AND COLUMN_NAME = ? '
            . 'AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?',
            ['reservations', 'fk_reservations_partner_apartment',
             'partner_apartment_id', 'partner_apartments', 'id']
        )->getRow();
        if ((int) $foreignKey->total === 0) {
            // Do not mask an existing constraint with this name but a different target.
            $sameName = $this->db->query(
                'SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS '
                . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
                ['fk_reservations_partner_apartment']
            )->getRow();
            if ((int) $sameName->total > 0) {
                throw new \RuntimeException('Foreign key name already exists with a different definition.');
            }
            $this->db->query(
                'ALTER TABLE reservations ADD CONSTRAINT fk_reservations_partner_apartment '
                . 'FOREIGN KEY (partner_apartment_id) REFERENCES partner_apartments(id) '
                . 'ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
        }
    }

    public function down()
    {
        if ($this->db->table('reservations')->where('unit_id', null)->countAllResults() > 0) {
            throw new \RuntimeException(
                'Cannot roll back while partner reservations exist. Back up and migrate them first.'
            );
        }

        $foreignKey = $this->db->query(
            'SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['reservations', 'fk_reservations_partner_apartment']
        )->getRow();
        if ((int) $foreignKey->total > 0) {
            $this->db->query(
                'ALTER TABLE reservations DROP FOREIGN KEY fk_reservations_partner_apartment'
            );
        }

        $index = $this->db->query(
            'SELECT COUNT(*) AS total FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['reservations', 'idx_reservations_partner_apartment_id']
        )->getRow();
        if ((int) $index->total > 0) {
            $this->db->query('DROP INDEX idx_reservations_partner_apartment_id ON reservations');
        }

        foreach (['booking_type', 'partner_apartment_id', 'owner_net_amount'] as $column) {
            if ($this->db->fieldExists($column, 'reservations')) {
                $this->forge->dropColumn('reservations', $column);
            }
        }

        $this->forge->modifyColumn('reservations', [
            'unit_id' => [
                'name' => 'unit_id', 'type' => 'INT',
                'unsigned' => true, 'null' => false,
            ],
        ]);
    }
}
