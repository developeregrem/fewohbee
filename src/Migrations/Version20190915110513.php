<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20190915110513 extends AbstractMigration
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
            $this->addSql("UPDATE template_types SET icon = 'fa-file-pdf' WHERE name IN ('TEMPLATE_FILE_PDF', 'TEMPLATE_INVOICE_PDF', 'TEMPLATE_RESERVATION_PDF', 'TEMPLATE_CASHJOURNAL_PDF', 'TEMPLATE_GDPR_PDF')");
            $this->addSql("UPDATE template_types SET icon = 'fa-envelope' WHERE name = 'TEMPLATE_RESERVATION_EMAIL'");
        }
    }

    public function down(Schema $schema) : void
    {
        // nothing to do
    }
}
