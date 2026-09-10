<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tokens = DB::table('personal_access_tokens')->get();
echo "TOTAL TOKENS IN DB: " . $tokens->count() . "\n";

foreach ($tokens as $t) {
    echo "ID: {$t->id} | Name: {$t->name} | Tokenable ID: {$t->tokenable_id} | Created: {$t->created_at}\n";
}
