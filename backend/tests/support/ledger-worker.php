<?php

use App\Domain\BudgetLedger;
use App\Domain\Idempotency;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$d = Document::findOrFail((int) $argv[1]);
$u = User::findOrFail($d->created_by);
$m = DB::table('memberships')->where('tenant_id', $d->tenant_id)->where('user_id', $u->id)->first();
$request = Request::create('/api/v1/concurrency', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['document_id' => $d->id]));
$request->headers->set('Idempotency-Key', $argv[2]);
$request->setUserResolver(fn () => $u);
$request->attributes->set('membership', $m);
while (microtime(true) < (float) $argv[3]) {
    usleep(1000);
}
try {
    $result = Idempotency::run($request, function () use ($d, $u) {
        BudgetLedger::move($d, 'reserve', '60', '0', '0', $u->id);
        usleep(50000);

        return ['document_id' => $d->id, 'reserved' => '60'];
    });
    echo json_encode(['accepted' => true, 'result' => $result]);
} catch (HttpException $e) {
    echo json_encode(['accepted' => false, 'status' => $e->getStatusCode()]);
}
