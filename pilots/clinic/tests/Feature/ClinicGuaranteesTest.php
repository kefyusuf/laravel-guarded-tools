<?php

namespace Tests\Feature;

use App\Mcp\Tools\AppointmentsToday;
use App\Mcp\Tools\PatientLookup;
use App\Models\Clinic;
use App\Models\User;
use GuardedTools\Packstub\HiddenCapabilities;
use GuardedTools\Testing\AssertsGuardedTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pilot 2: clinic. Sensitive data, a tenant column named clinic_id, and a role
 * (receptionist) that must never see patient details.
 */
class ClinicGuaranteesTest extends TestCase
{
    use AssertsGuardedTools, RefreshDatabase;

    private Clinic $north;
    private Clinic $south;
    private User $doctorA;
    private User $doctorB;

    protected function guardedToolTables(string $tool): array
    {
        return ['patients', 'appointments'];
    }

    protected function guardedWorkspaceColumn(string $tool): string
    {
        return 'clinic_id';
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 09:00:00');
        config(['packstub-agents.enabled' => true]);

        $this->north = Clinic::create(['name' => 'Kuzey Klinik', 'slug' => 'kuzey']);
        $this->south = Clinic::create(['name' => 'Güney Klinik', 'slug' => 'guney']);
        $this->doctorA = User::factory()->create(['role' => 'doctor', 'clinic_id' => $this->north->id]);
        $this->doctorB = User::factory()->create(['role' => 'doctor', 'clinic_id' => $this->south->id]);

        $ayse = $this->patient($this->north, 'Ayşe Yılmaz', 1984);
        $mehmet = $this->patient($this->north, 'Mehmet Yıldız', 1972);
        $zeynep = $this->patient($this->south, 'Zeynep Yılmaz', 1990);
        $this->appointment($this->north, $ayse, '11:00', 'scheduled');
        $this->appointment($this->north, $mehmet, '08:00', 'completed');
        $this->appointment($this->south, $zeynep, '10:00', 'scheduled');
        $this->appointment($this->south, $zeynep, '14:00', 'scheduled');
        $this->appointment($this->south, $zeynep, '15:00', 'cancelled');

        $this->actingAs($this->doctorA);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function patient(Clinic $clinic, string $name, int $birthYear): int
    {
        return DB::table('patients')->insertGetId(['clinic_id' => $clinic->id, 'name' => $name, 'birth_year' => $birthYear]);
    }

    private function appointment(Clinic $clinic, int $patient, string $time, string $status): void
    {
        DB::table('appointments')->insert(['clinic_id' => $clinic->id, 'patient_id' => $patient,
            'starts_at' => now()->setTimeFromTimeString($time), 'status' => $status]);
    }

    public static function tools(): array
    {
        return [
            'appointments' => [AppointmentsToday::class, [], 'db:appointments'],
            'patients' => [PatientLookup::class, ['query' => 'Yılmaz'], 'db:patients'],
        ];
    }

    #[DataProvider('tools')]
    public function test_kit_guarantees(string $tool, array $arguments, string $source): void
    {
        $this->assertToolIsWorkspaceBound($tool, $arguments, $this->north, $this->south, $this->doctorA, $this->doctorB);
        $this->assertRejectsUnknownArguments($tool, $arguments);
        $this->assertNonMemberIsDenied($tool, $arguments, $this->doctorB, $this->north);
        $this->assertEvidenceChain($tool, $arguments, $this->doctorA, $this->north, $source);
        $this->assertToolCallBudgetIsEnforced($tool, $arguments, $this->doctorA, $this->north);
        $this->assertRevokedMemberIsDenied($tool, $arguments, $this->doctorA, $this->north,
            fn () => User::whereKey($this->doctorA->id)->update(['clinic_id' => $this->south->id]));
    }

    #[DataProvider('tools')]
    public function test_failure_is_canonical(string $tool, array $arguments, string $source): void
    {
        $this->assertFailureIsCanonical($tool, $arguments, function (): void {
            Schema::rename('patients', 'patients_offline');
            Schema::rename('appointments', 'appointments_offline');
        });
    }

    public function test_patient_search_never_returns_another_clinics_patient(): void
    {
        // "Yılmaz" exists in both clinics; only the north patient may come back.
        $payload = $this->decodeGuardedResult($this->runGuardedTool(PatientLookup::class, ['query' => 'Yılmaz'], $this->doctorA, $this->north));
        $this->assertSame(['Ayşe Yılmaz'], array_column($payload['data']['patients'], 'name'));
    }

    public function test_receptionist_sees_counts_but_no_patient_tool_and_no_patient_names(): void
    {
        $receptionist = User::factory()->create(['role' => 'receptionist', 'clinic_id' => $this->north->id]);
        $this->actingAs($receptionist);

        $this->assertFalse((new PatientLookup)->shouldRegister());
        $line = HiddenCapabilities::contextLine();
        $this->assertStringContainsString('patient-lookup', $line);

        $result = $this->runGuardedTool(AppointmentsToday::class, [], $receptionist, $this->north);
        $raw = (string) $result->toolCalls()[0]['result'];
        $this->assertSame(2, $this->decodeGuardedResult($result)['data']['appointments']);
        foreach (['Ayşe', 'Mehmet', 'Zeynep', 'Yılmaz'] as $name) {
            $this->assertStringNotContainsString($name, $raw);
            $this->assertStringNotContainsString($name, $line);
        }
    }
}
