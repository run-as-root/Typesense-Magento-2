<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\CohortAnalysisTool;

final class CohortAnalysisToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private CohortAnalysisTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new CohortAnalysisTool($this->resource);
    }

    public function test_get_name_returns_cohort_analysis(): void
    {
        self::assertSame('cohort_analysis', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_required_cohort_by(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertContains('cohort_by', $schema['required']);
    }

    public function test_get_parameters_schema_cohort_by_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $prop = $schema['properties']['cohort_by'];

        self::assertArrayHasKey('enum', $prop);
        self::assertContains('first_purchase_month', $prop['enum']);
        self::assertContains('first_purchase_quarter', $prop['enum']);
    }

    public function test_execute_returns_error_for_invalid_cohort_by(): void
    {
        $result = json_decode($this->sut->execute([
            'cohort_by' => 'invalid',
        ]), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_returns_error_for_out_of_range_periods(): void
    {
        $result = json_decode($this->sut->execute([
            'cohort_by' => 'first_purchase_month',
            'periods' => 0,
        ]), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_returns_empty_cohorts_when_no_data(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willReturn([]);

        $result = json_decode($this->sut->execute([
            'cohort_by' => 'first_purchase_month',
        ]), true);

        self::assertArrayHasKey('cohorts', $result);
        self::assertEmpty($result['cohorts']);
    }

    public function test_execute_builds_retention_matrix(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);

        // Customer 1 bought in 2025-01 (cohort) and again in 2025-02
        // Customer 2 bought in 2025-01 (cohort) but not again
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'cohort' => '2025-01', 'order_period' => '2025-01'],
            ['customer_id' => 1, 'cohort' => '2025-01', 'order_period' => '2025-02'],
            ['customer_id' => 2, 'cohort' => '2025-01', 'order_period' => '2025-01'],
        ]);

        $result = json_decode($this->sut->execute([
            'cohort_by' => 'first_purchase_month',
            'periods' => 6,
        ]), true);

        self::assertArrayHasKey('cohorts', $result);
        self::assertArrayHasKey('2025-01', $result['cohorts']);

        $cohort = $result['cohorts']['2025-01'];
        self::assertSame(2, $cohort['cohort_size']);

        // Period 0 = cohort period itself (100% retention)
        $period0 = $cohort['periods'][0];
        self::assertSame(100.0, $period0['retention_rate']);
        self::assertSame(2, $period0['returning_customers']);

        // Period 1 = 2025-02: only customer 1 returned (50%)
        $period1 = $cohort['periods'][1];
        self::assertSame(50.0, $period1['retention_rate']);
        self::assertSame(1, $period1['returning_customers']);
    }

    public function test_execute_handles_query_exception(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchAll')->willThrowException(new \Exception('DB error'));

        $result = json_decode($this->sut->execute([
            'cohort_by' => 'first_purchase_month',
        ]), true);

        self::assertArrayHasKey('error', $result);
    }
}
