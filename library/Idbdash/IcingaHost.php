<?php

namespace Icinga\Module\Idbdash;

use Icinga\Module\Icingadb\Model\Host;
use ipl\Sql\Expression;

class IcingaHost extends Host
{
    public function getColumns(): array
    {
        return parent::getColumns() + [
            'len' => new Expression(
                'LENGTH(host.name)'
            ),
        ];
    }

    public function getColumnDefinitions(): array
    {
        return parent::getColumnDefinitions() + [
                'len' => t('Host Name Length'),
        ];
    }
}
