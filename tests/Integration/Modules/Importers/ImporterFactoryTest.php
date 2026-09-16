<?php

namespace FolderFolio\Tests\Unit\Modules\Importers;

use FolderFolio\Modules\Importers\FileBirdImporter;
use FolderFolio\Modules\Importers\ImporterFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ImporterFactoryTest extends TestCase
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
