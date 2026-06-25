<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mail and SMTP settings to general app settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_settings ADD mail_from_email VARCHAR(255) DEFAULT NULL, ADD mail_from_name VARCHAR(255) DEFAULT NULL, ADD mail_return_path VARCHAR(255) DEFAULT NULL, ADD mail_copy TINYINT(1) DEFAULT NULL, ADD smtp_host VARCHAR(255) DEFAULT NULL, ADD smtp_port INT DEFAULT NULL, ADD smtp_encryption VARCHAR(20) DEFAULT NULL, ADD smtp_username VARCHAR(255) DEFAULT NULL, ADD smtp_password_encrypted LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_settings DROP mail_from_email, DROP mail_from_name, DROP mail_return_path, DROP mail_copy, DROP smtp_host, DROP smtp_port, DROP smtp_encryption, DROP smtp_username, DROP smtp_password_encrypted');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
