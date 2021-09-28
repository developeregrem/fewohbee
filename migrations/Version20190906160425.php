<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20190906160425 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        $sql = "SELECT * FROM template_types";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        $count = $stmt->rowCount();
        // execute the following only when the table is not empty
        if($count > 0) {
            $this->addSql("UPDATE template_types SET editor_template = 'editor_template_reservation.json.twig' WHERE name = 'TEMPLATE_RESERVATION_EMAIL'");
            $this->addSql("UPDATE template_types SET editor_template = 'editor_template_default.json.twig' WHERE name = 'TEMPLATE_FILE_PDF'");
            $this->addSql("UPDATE template_types SET editor_template = 'editor_template_invoice.json.twig' WHERE name = 'TEMPLATE_INVOICE_PDF'");
            $this->addSql("UPDATE template_types SET editor_template = 'editor_template_reservation.json.twig' WHERE name = 'TEMPLATE_RESERVATION_PDF'");
            $this->addSql("UPDATE template_types SET editor_template = 'editor_template_cashjournal.json.twig' WHERE name = 'TEMPLATE_CASHJOURNAL_PDF'");
            $this->addSql("UPDATE template_types SET editor_template = 'editor_template_customer.json.twig' WHERE name = 'TEMPLATE_GDPR_PDF'");
        }
    }

    public function down(Schema $schema) : void
    {
        // nothing to do
    }
    
    public function isTransactional(): bool
    {
        return false;
    }
}
