<?php

namespace FolderFolio\Tests\Integration\Modules\Importers;

use FolderFolio\Modules\Importers\FileBirdImporter;
use FolderFolio\Modules\Importers\ImporterFactory;
use InvalidArgumentException;
use WP_UnitTestCase;

class ImporterFactoryTest extends WP_UnitTestCase
{
    /**
     * @test
     */
    public function creates_filebird_importer(): void
    {
        $factory = new ImporterFactory();
        $importer = $factory->create('filebird');

        $this->assertInstanceOf(FileBirdImporter::class, $importer);
        $this->assertSame('FileBird', $importer->getName());
    }

    /**
     * @test
     */
    public function rejects_unknown_importer(): void
    {
        $factory = new ImporterFactory();

        $this->expectException(InvalidArgumentException::class);
        $factory->create('unknown-plugin');
    }
}
