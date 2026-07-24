<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The README is the first file anyone opens, and its pointer list is the only
 * index of the operations documentation. That list is a mirror of a directory,
 * so it drifts the moment a document is added and nobody remembers the index:
 * it had drifted to one of five entries, which made four operations documents
 * effectively invisible to the person who needs them most, the admin who just
 * took over.
 *
 * Directory is the SSoT, README mirrors it. Runs on the host and in the QA
 * lane's full-repo mount; inside the PHP container only Docker/WebAPI exists,
 * and the pattern for that is HttpsConfigTest's.
 */
final class DocIndexContractTest extends TestCase
{
    public function testReadmePointsToEveryOperationsDocument(): void
    {
        $root = dirname(__DIR__, 4);
        $readme = $root . DIRECTORY_SEPARATOR . 'README.md';
        if (!is_file($readme)) {
            self::markTestSkipped('Repo root not visible; README.md only exists outside the container mount.');
        }

        $documents = glob($root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'operations' . DIRECTORY_SEPARATOR . '*.md') ?: [];
        self::assertNotSame([], $documents, 'No operations documents found (zero-match must not pass).');

        $index = (string) file_get_contents($readme);
        foreach ($documents as $document) {
            $name = basename($document);
            self::assertStringContainsString(
                $name,
                $index,
                sprintf('README.md does not point to docs/operations/%s; a document nobody is sent to is a document nobody reads.', $name)
            );
        }
    }
}
