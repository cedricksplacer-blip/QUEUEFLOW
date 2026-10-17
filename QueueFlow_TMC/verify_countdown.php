<?php

use App\Models\Office;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\QueueCounter;
use App\Services\QueueDeploymentManager;
use App\Services\QueueTicketCreator;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.default' => 'mysql']);
DB::purge('mysql');

function check($label, $cond) {
    echo ($cond ? "PASS" : "FAIL")." - $label\n";
    if (!$cond) exit(1);
}

// Use test DB to avoid touching live data
config(['database.connections.mysql.database' => 'queueflow_tmc_test']);
DB::purge('mysql');
DB::reconnect('mysql');

Artisan::call('migrate', ['--force' => true]);

DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('staff_notifications')->truncate();
DB::table('queue_tickets')->truncate();
DB::table('queue_deployments')->truncate();
DB::table('offices')->truncate();
DB::table('users')->truncate();
DB::table('office_services')->truncate();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$mkOffice = fn($name,$code) => Office::create(['name'=>$name,'code'=>$code,'is_active'=>true,'status'=>Office::STATUS_ONLINE,'opening_time'=>'00:00','closing_time'=>'23:59','max_slots'=>100]);
$mkUser = fn($role,$officeId=null) => User::create(['name'=>"$role User",'email'=>$role.'_'.uniqid().'@t.test','password'=>'password','role'=>$role,'is_active'=>true,'office_id'=>$officeId]);
$mkService = fn($office) => \App\Models\OfficeService::create(['office_id'=>$office->id,'name'=>'General','requires_course'=>false,'is_active'=>true]);

$reg = $mkOffice('Registrar BSIT','REG-BSIT');
$cash = $mkOffice('Cashier','CASH');
$staffReg = $mkUser(User::ROLE_STAFF, $reg->id);
$staffCash = $mkUser(User::ROLE_STAFF, $cash->id);
$sReg = $mkService($reg);
$sCash = $mkService($cash);
$mgr = new QueueDeploymentManager();
$mgr->start($reg); $mgr->deploy($reg);
$mgr->start($cash); $mgr->deploy($cash);
$creator = new QueueTicketCreator();
$counter = new QueueCounter();

// 1. Staff selects 3 minutes
$t1 = $creator->create(['office_id'=>$reg->id,'service_id'=>$sReg->id,'priority'=>'regular'])['ticket'];
$called1 = $counter->callNext($reg, $staffReg, null, 3);
check('3-min call stores duration=3', $called1->fresh()->call_duration_minutes === 3);
check('3-min expiry ~3min', abs($called1->fresh()->called_at->diffInMinutes($called1->fresh()->expires_at)) === 3);
$counter->serve($called1->fresh(), $staffReg, $reg);

// 2. Staff selects 5 minutes
$t2 = $creator->create(['office_id'=>$reg->id,'service_id'=>$sReg->id,'priority'=>'regular'])['ticket'];
$called2 = $counter->callNext($reg, $staffReg, null, 5);
check('5-min call stores duration=5', $called2->fresh()->call_duration_minutes === 5);
$counter->serve($called2->fresh(), $staffReg, $reg);

// 3. Staff selects 10 minutes
$t3 = $creator->create(['office_id'=>$reg->id,'service_id'=>$sReg->id,'priority'=>'regular'])['ticket'];
$called3 = $counter->callNext($reg, $staffReg, null, 10);
check('10-min call stores duration=10', $called3->fresh()->call_duration_minutes === 10);
check('10-min expiry = called+10', $called3->fresh()->expires_at->equalTo($called3->fresh()->called_at->copy()->addMinutes(10)));

// 4. Refresh preserves deadline (re-read row, same expires_at)
$exp = $called3->fresh()->expires_at->toIso8601String();
check('refresh keeps expires_at', QueueTicket::find($called3->id)->expires_at->toIso8601String() === $exp);

// 5. Invalid durations rejected
foreach ([0, -1, 61, 999] as $bad) {
    try { $counter->callNext($cash, $staffCash, null, $bad); check("invalid $bad rejected", false); }
    catch (Illuminate\Validation\ValidationException $e) { check("invalid $bad rejected", true); }
}
// cash office still has no serving ticket after rejections
check('invalid does not create serving', !QueueTicket::where('office_id',$cash->id)->where('status','serving')->exists());

// 6. Per-office independence: cashier 15 min does not touch registrar active call
$tc = $creator->create(['office_id'=>$cash->id,'service_id'=>$sCash->id,'priority'=>'regular'])['ticket'];
$calledC = $counter->callNext($cash, $staffCash, null, 15);
check('cash 15-min stored', $calledC->fresh()->call_duration_minutes === 15);
check('registrar still 10-min', $called3->fresh()->call_duration_minutes === 10);

// 7. Cancel allows next student immediately
$counter->cancelCall($called3->fresh());
check('cancelled status', QueueTicket::find($called3->id)->status === 'cancelled');
check('cancel clears expires', QueueTicket::find($called3->id)->expires_at === null);
$t4 = $creator->create(['office_id'=>$reg->id,'service_id'=>$sReg->id,'priority'=>'regular'])['ticket'];
$called4 = $counter->callNext($reg, $staffReg, null, 5);
check('next callable after cancel', $called4 !== null && $called4->queue_number === $t4->queue_number);

// 8. Expired blocks until staff resolve via Call Again / No-Show
$serving = $called4->fresh();
$serving->update(['expires_at' => now()->subMinute()]);
check('fixture expired', $serving->fresh()->isExpired());
$t5 = $creator->create(['office_id'=>$reg->id,'service_id'=>$sReg->id,'priority'=>'regular'])['ticket'];
$blocked = $counter->callNext($reg, $staffReg, null, 5);
check('expired still blocks next call', $blocked === null);
check('expired stays serving', QueueTicket::find($serving->id)->status === 'serving');
// Call Again re-arms same ticket
$recalled = $counter->recall($serving->fresh(), $staffReg, $reg, 5);
check('recall keeps same ticket', $recalled->id === $serving->id && $recalled->status === 'serving');
check('recall fresh deadline', !$recalled->fresh()->isExpired() && $recalled->fresh()->recall_count >= 1);
// Expire again then No-Show terminals and frees window
$recalled->fresh()->update(['expires_at' => now()->subMinute()]);
$counter->markNoShow($recalled->fresh(), $staffReg);
check('no-show terminal', QueueTicket::find($serving->id)->status === 'no_show');
$called5 = $counter->callNext($reg, $staffReg, null, 5);
check('next callable after no-show', $called5 !== null && $called5->id === $t5->id);

// 9. No-show not counted as served, counted as no_show
check('no-show not served count', QueueTicket::where('office_id',$reg->id)->where('status','served')->count() >= 2);
check('no-show counted', QueueTicket::where('office_id',$reg->id)->where('status','no_show')->count() >= 1);

// 10. remainingSeconds / isExpired helpers
check('remaining>0 for fresh call', $called5->fresh()->remainingSeconds() > 0);

echo "ALL SCENARIO CHECKS PASSED\n";
