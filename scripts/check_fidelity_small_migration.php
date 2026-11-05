<?php

// Local investigation only. Refuses to run on the application's database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$trial = '/private/tmp/cscs-fidelity-local-trial';
$snapshot = $trial.'/migrated.sqlite';
if (! is_file($snapshot) || realpath($snapshot) === realpath(database_path('database.sqlite'))) {
    throw new RuntimeException('A separate migrated database snapshot is required.');
}
config([
    'database.default' => 'sqlite', 'database.connections.sqlite.url' => null,
    'database.connections.sqlite.database' => $snapshot,
    'filesystems.default' => 'local', 'filesystems.disks.local.root' => $trial.'/storage',
    'cache.default' => 'array', 'logging.default' => 'single',
    'logging.channels.single.path' => $trial.'/trial.log',
]);
DB::purge('sqlite');
use App\Models\AdminUser;
use App\Models\CscsSecurityMapping;
use App\Models\CscsUploadBatch;
use App\Models\CscsUploadRow;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\ShareholderRegisterAccount;
use App\Models\SharePosition;
use App\Services\CscsImportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

$actual = DB::select('PRAGMA database_list')[0]->file;
if (realpath($actual) !== realpath($snapshot)) {
    throw new RuntimeException('Snapshot connection check failed.');
}
$report = ['database' => 'Consistent temporary copy of the actual local migrated database', 'phases' => []];
function phase(string $label, int $batchId): void
{
    global $report;
    $batch = CscsUploadBatch::findOrFail($batchId);
    $rows = CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'movement')->get();
    $value = ['phase' => $label, 'workflow_status' => $batch->workflow_status, 'exceptions' => $rows->whereNotNull('exception_code')->count(), 'rows' => $rows->map(fn ($r) => ['identifier' => $r->identifier_value, 'exception' => $r->exception_code, 'match_method' => $r->match_method, 'sra_id' => $r->proposed_sra_id, 'status' => $r->status])->all()];
    $report['phases'][] = $value;
    echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),PHP_EOL;
}
try {
    if (in_array('--resume-post', $argv, true)) {
        $report = json_decode(file_get_contents(base_path('outputs/cscs-fidelity-local-trial/result.json')), true, 512, JSON_THROW_ON_ERROR);
        $batch = CscsUploadBatch::where('description', 'Actual migrated-data snapshot trial')->sole();
        $batchId = $batch->id;
        $actors = ['poster' => AdminUser::where('email', 'like', 'local-cscs-poster-%')->sole()];
        $service = app(CscsImportService::class);
        $class = ShareClass::where('register_id', $batch->register_id)->where('class_code', 'ORDINARY')->sole();
        $positions = [];
        foreach ($report['source_links'] as $link) {
            $position = SharePosition::where('sra_id', $link['sra_id'])->where('share_class_id', $class->id)->sole();
            $positions[] = ['sra_id' => $link['sra_id'], 'legacy_account' => $link['account'], 'before' => $position->quantity, 'holding_mode' => $position->holding_mode];
        }
        goto resumePosting;
    }
    $register = Register::where('register_code', '87')->sole();
    $class = ShareClass::where('register_id', $register->id)->where('class_code', 'ORDINARY')->sole();
    $actors = [];
    foreach (['maker', 'checker', 'poster'] as $role) {
        $actors[$role] = AdminUser::create(['email' => "local-cscs-{$role}-".bin2hex(random_bytes(4)).'@example.test', 'first_name' => 'Snapshot', 'last_name' => ucfirst($role), 'is_active' => true]);
    }
    $service = app(CscsImportService::class);
    $files = [];
    foreach (['FIDELITYBK_small_mast.txt', 'FIDELITYBK_small_s6.txt'] as $name) {
        $files[] = new UploadedFile(base_path('outputs/cscs-fidelity-small-test/'.$name), $name, 'text/plain', null, true);
    }
    $result = $service->import($files, $register->id, $actors['maker']->id, 'Actual migrated-data snapshot trial');
    $batchId = $result['batch_id'];
    phase('1. Actual migrated data, unchanged identifiers', $batchId);
    CscsSecurityMapping::updateOrCreate(['security_code' => 'FIDELITYBK'], ['register_id' => $register->id, 'share_class_id' => $class->id, 'is_active' => true]);
    try {
        $service->reconcile($batchId, $actors['maker']->id, 'Snapshot security mapping corrected');
    } catch (ValidationException $e) {
        $report['mapping_reconcile_error'] = $e->errors();
    }
    phase('2. Correct security mapping only', $batchId);
    // Validate the crosswalk against the actual source ledger before changing the snapshot.
    $source = new PDO('sqlite:file:'.base_path('FRIS.sqlite').'?mode=ro');
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $plan = [
        ['account' => '377853', 'rg' => 'RG4285246', 'chn' => 'C5520941OH', 'tx' => '2606160005615005', 'qty' => 5000],
        ['account' => '367705', 'rg' => 'RG4125842', 'chn' => 'C0618216AD', 'tx' => '2606160005615010', 'qty' => 1000],
        ['account' => '260382', 'rg' => 'RG4137963', 'chn' => 'C000677962AB', 'tx' => '2606160005615012', 'qty' => 5000],
    ];
    $statement = $source->prepare('SELECT id,acctno,units FROM units WHERE regcode=87 AND xfer_no=?');
    $positions = [];
    foreach ($plan as $link) {
        $statement->execute([$link['tx']]);
        $evidence = $statement->fetchAll(PDO::FETCH_ASSOC);
        $quantities = array_map(fn ($r) => (float) $r['units'], $evidence);
        sort($quantities);
        if (count($evidence) !== 2 || array_unique(array_column($evidence, 'acctno')) != [$link['account']] || $quantities !== [-(float) $link['qty'], (float) $link['qty']]) {
            throw new RuntimeException('Source ledger did not uniquely support '.$link['tx']);
        }
        $account = ShareholderRegisterAccount::where('register_id', $register->id)->where('shareholder_no', $link['account'])->sole();
        foreach (['chn' => $link['chn'], 'cscs_account_no' => $link['rg']] as $column => $identifier) {
            if ($account->$column && $identifier !== $account->$column) {
                throw new RuntimeException('Existing identifier conflict');
            }
            if (ShareholderRegisterAccount::where('register_id', $register->id)->where($column, $identifier)->where('id', '!=', $account->id)->exists()) {
                throw new RuntimeException('Identifier belongs to another account');
            }
        }
        $position = SharePosition::where('sra_id', $account->id)->where('share_class_id', $class->id)->sole();
        $positions[] = ['sra_id' => $account->id, 'legacy_account' => $link['account'], 'before' => $position->quantity, 'holding_mode' => $position->holding_mode];
        $account->update(['chn' => $link['chn'], 'cscs_account_no' => $link['rg']]);
        $report['source_links'][] = $link + ['sra_id' => $account->id, 'source_unit_ids' => array_column($evidence, 'id')];
    }
    $service->reconcile($batchId, $actors['maker']->id, 'Source-verified register identifier repair in snapshot only');
    phase('3. Permanent identifiers repaired on existing migrated accounts', $batchId);
    if (! Schema::hasTable('cscs_batch_snapshots')) {
        $migration = require base_path('database/migrations/2026_08_13_000001_harden_cscs_workflow.php');
        $migration->up();
        $report['schema_repairs'] = ['Applied existing 2026_08_13_000001_harden_cscs_workflow migration on temporary snapshot only'];
    }
    $service->submit($batchId, $actors['maker']->id, 'Local snapshot approval test');
    $steps = CscsUploadBatch::findOrFail($batchId)->required_approval_steps;
    foreach ($steps as $step) {
        $checker = AdminUser::create(['email' => 'local-checker-'.bin2hex(random_bytes(4)).'@example.test', 'first_name' => 'Snapshot', 'last_name' => 'Checker', 'is_active' => true]);
        foreach ($step['roles'] ?? [] as $role) {
            $existing = Role::where('name', $role)->where('guard_name', 'web')->first();
            if ($existing) {
                $checker->assignRole($existing);
                break;
            }
        }
        $service->approve($batchId, $checker, 'Snapshot only');
    }
    resumePosting:
    foreach (DB::select('PRAGMA foreign_key_list(share_transactions)') as $foreignKey) {
        if ($foreignKey->from === 'created_by' && $foreignKey->table === 'users') {
            Schema::table('share_transactions', function ($table) {
                $table->dropForeign(['created_by']);
            });
            Schema::table('share_transactions', function ($table) {
                $table->foreign('created_by')->references('id')->on('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            });
            $report['schema_repairs'][] = 'Corrected share_transactions.created_by FK from users to admin_users in snapshot, matching current table migration';
        }
    }
    $service->post($batchId, $actors['poster'], 'Snapshot only; no production posting');
    unset($report['error'], $report['error_class'], $report['validation_errors']);
    phase('4. Posted in temporary migrated-data snapshot', $batchId);
    foreach ($positions as &$p) {
        $p['after'] = SharePosition::where('sra_id', $p['sra_id'])->where('share_class_id', $class->id)->value('quantity');
    }unset($p);
    $report['positions'] = $positions;
    $report['new_ledger_entries'] = CscsUploadRow::where('batch_id', $batchId)->whereNotNull('share_transaction_id')->count();
    $report['post_verification'] = CscsUploadBatch::findOrFail($batchId)->reconciliation['post_verification'] ?? null;
    $report['result'] = 'PASS on snapshot after verified identifier repairs; unchanged migrated data failed';
} catch (Throwable $e) {
    $report['result'] = 'BLOCKED';
    $report['error'] = $e->getMessage();
    $report['error_class'] = get_class($e);
    if ($e instanceof ValidationException) {
        $report['validation_errors'] = $e->errors();
    }
    echo json_encode(['error' => $report['error'], 'class' => $report['error_class']], JSON_PRETTY_PRINT),PHP_EOL;
} finally {
    file_put_contents(base_path('outputs/cscs-fidelity-local-trial/result.json'),json_encode($report,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
