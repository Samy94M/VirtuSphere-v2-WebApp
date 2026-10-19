<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/validate.php';
require_once dirname(__DIR__, 2) . '/lib/vm_edit_values.php';

/**
 * QL-06: an emptied CPU field in the VM editor is the same "required" error the RAM
 * field gives, not a silent save with the repository default (2 CPUs).
 */
final class VmEditCpuFieldTest extends TestCase
{
    public function testAnEmptiedOrMissingCpuFieldIsRefusedAsRequired(): void
    {
        $expected = __t('validate.required', ['field' => __t('vm_edit.label_cpu')]);

        foreach ([['vm_cpu' => ''], ['vm_cpu' => "  \t"], []] as $post) {
            try {
                vm_edit_cpu_from_post($post);
                self::fail('an empty CPU field must not be replaced by a default');
            } catch (ValidationException $exception) {
                self::assertSame(['vm_cpu' => $expected], $exception->errors());
                self::assertSame($expected, $exception->getMessage());
            }
        }
    }

    public function testAPresentValueIsPassedOnTrimmedAndTheRepositoryKeepsTheRange(): void
    {
        self::assertSame('4', vm_edit_cpu_from_post(['vm_cpu' => ' 4 ']));
        // A value that is present but not a number is not this helper's decision: the repository answers it.
        self::assertSame('abc', vm_edit_cpu_from_post(['vm_cpu' => 'abc']));
    }
}
