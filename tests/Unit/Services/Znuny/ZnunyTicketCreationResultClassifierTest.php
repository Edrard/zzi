<?php

namespace Tests\Unit\Services\Znuny;

use App\Enums\ZnunyTicketCreationClassification;
use App\Services\Znuny\ZnunyTicketCreationMarkerBuilder;
use App\Services\Znuny\ZnunyTicketCreationReliabilityService;
use App\Services\Znuny\ZnunyTicketCreationResultClassifier;
use PHPUnit\Framework\TestCase;

class ZnunyTicketCreationResultClassifierTest extends TestCase
{
    private ZnunyTicketCreationResultClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ZnunyTicketCreationResultClassifier;
    }

    public function test_boolean_true_with_valid_identifiers_is_success()
    {
        $result = $this->classifier->classify([
            'success' => true,
            'ticket_id' => 123,
            'ticket_number' => 'TN123',
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Success, $result);
    }

    public function test_boolean_false_with_meaningful_errors_and_no_identifiers_is_confirmed_failed()
    {
        $result = $this->classifier->classify([
            'success' => false,
            'errors' => ['Queue invalid'],
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::ConfirmedFailed, $result);
    }

    public function test_boolean_false_without_errors_is_uncertain()
    {
        $result = $this->classifier->classify([
            'success' => false,
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result);
    }

    public function test_boolean_false_with_ticket_id_is_uncertain()
    {
        $result = $this->classifier->classify([
            'success' => false,
            'ticket_id' => 123,
            'errors' => ['Queue invalid'],
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result);
    }

    public function test_boolean_true_with_missing_ticket_id_is_uncertain()
    {
        $result = $this->classifier->classify([
            'success' => true,
            'ticket_number' => 'TN123',
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result);
    }

    public function test_boolean_true_with_missing_ticket_number_is_uncertain()
    {
        $result = $this->classifier->classify([
            'success' => true,
            'ticket_id' => 123,
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result);
    }

    public function test_missing_success_flag_is_uncertain()
    {
        $result = $this->classifier->classify([
            'ticket_id' => 123,
            'ticket_number' => 'TN123',
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result);
    }

    public function test_string_or_integer_truthy_success_values_are_uncertain()
    {
        $result1 = $this->classifier->classify([
            'success' => 1,
            'ticket_id' => 123,
            'ticket_number' => 'TN123',
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result1);

        $result2 = $this->classifier->classify([
            'success' => 'true',
            'ticket_id' => 123,
            'ticket_number' => 'TN123',
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result2);
    }

    public function test_invalid_ticket_id_is_uncertain()
    {
        $invalidIds = [0, '0', '', 'abc', false];

        foreach ($invalidIds as $id) {
            $result = $this->classifier->classify([
                'success' => true,
                'ticket_id' => $id,
                'ticket_number' => 'TN123',
            ]);
            $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result);
        }
    }

    public function test_nested_meaningful_error_arrays_are_confirmed_failed()
    {
        $result = $this->classifier->classify([
            'success' => false,
            'errors' => [['Message' => 'Queue invalid']],
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::ConfirmedFailed, $result);
    }

    public function test_empty_nested_errors_are_uncertain()
    {
        $result = $this->classifier->classify([
            'success' => false,
            'errors' => [[], [''], [null]],
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result);
    }

    public function test_boolean_false_with_ticket_number_is_uncertain()
    {
        $result = $this->classifier->classify([
            'success' => false,
            'ticket_number' => 'TN123',
            'errors' => ['Rejected'],
        ]);
        $this->assertEquals(ZnunyTicketCreationClassification::Uncertain, $result);
    }

    public function test_normalization_produces_useful_details_without_persisting_secret_value()
    {
        $builder = $this->createStub(ZnunyTicketCreationMarkerBuilder::class);
        $reliability = new ZnunyTicketCreationReliabilityService($builder, $this->classifier);

        $details = $reliability->buildSafeErrorDetails([
            'success' => false,
            'errors' => [
                'Error' => [
                    'Message' => 'Queue invalid',
                    'Token' => 'secret-value',
                ],
            ],
        ], ZnunyTicketCreationClassification::ConfirmedFailed);

        $this->assertStringContainsString('Queue invalid', $details);
        $this->assertStringContainsString('[REDACTED]', $details);
        $this->assertStringNotContainsString('secret-value', $details);
    }

    public function test_exception_details_redact_sensitive_patterns()
    {
        $builder = $this->createStub(ZnunyTicketCreationMarkerBuilder::class);
        $reliability = new ZnunyTicketCreationReliabilityService($builder, $this->classifier);

        $sanitized = $reliability->sanitizeExceptionMessage('Error token=secret-value password: mypass');

        $this->assertStringContainsString('token=[REDACTED]', $sanitized);
        $this->assertStringContainsString('password: [REDACTED]', $sanitized);
        $this->assertStringNotContainsString('secret-value', $sanitized);
        $this->assertStringNotContainsString('mypass', $sanitized);
    }
}
