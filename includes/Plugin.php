<?php

declare(strict_types=1);

namespace FolderFolio;

use FolderFolio\Admin\AdminPage;
use FolderFolio\Modules\FoldersModule;

/**
 * Main plugin class.
 */
final class Plugin
{
    /**
     * Initialize the plugin.
     *
     * @return void
     */
    public static function init(): void
    {
        AdminPage::register();
        FoldersModule::register();
    }
}
