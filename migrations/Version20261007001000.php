<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio sourcing module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\Sourcing\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The need a room request answers, when it was made from one.
 */
final class Version20261007001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sourcing_room_request.need';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sourcing_room_request ADD need VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sourcing_room_request DROP need');
    }
}
