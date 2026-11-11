<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class DomainIndependenceTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_FRAMEWORK_NAMESPACES = [
        'Illuminate\\',
        'Laravel\\',
        'Symfony\\',
    ];

    #[Test]
    public function domain_layer_does_not_depend_on_framework_code(): void
    {
        $domainPath = dirname(__DIR__, 2).'/app/Domain';

        self::assertDirectoryExists($domainPath);

        $violations = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($domainPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if ($source === false) {
                throw new RuntimeException("Could not read {$file->getPathname()}.");
            }

            foreach (self::FORBIDDEN_FRAMEWORK_NAMESPACES as $namespace) {
                if (str_contains($source, $namespace)) {
                    $violations[] = "{$file->getFilename()} references {$namespace}";
                }
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }
}
