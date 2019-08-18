<?php

namespace Kanboard\Plugin\TdgImport;

use Kanboard\Core\Base;

class TdgImportPlugin extends Base
{
    public function initialize()
    {
    }

    public function getPluginName()
    {
        return 'TdgImport';
    }

    public function getPluginDescription()
    {
        return t('Import TODO tasks in tdg format');
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

    public function getCompatibleVersion() {
        return '>=1.2.5';
    }
}
