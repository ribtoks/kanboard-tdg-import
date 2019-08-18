<?php

namespace Kanboard\Plugin\TdgImport;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\TdgImport\Api\ImportTODOProcedure;

class Plugin extends Base
{
    public function initialize()
    {
        $this->api->getProcedureHandler()->withObject(new ImportTODOProcedure($this->container));
    }

    public function getPluginName()
    {
        return 'TdgImport';
    }

    public function getPluginAuthor()
    {
        return 'Taras Kushnir';
    }

    public function getPluginVersion()
    {
        return '0.0.1';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/ribtoks/kanboard-tdg-import';
    }
}