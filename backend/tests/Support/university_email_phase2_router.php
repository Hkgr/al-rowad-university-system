<?php
// Test-only PHP built-in server router. Never register in application providers.
use Illuminate\Support\Facades\{DB, Http};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = realpath((string) getenv('UNIVERSITY_EMAIL_PHASE2_BROWSER_DIR'));
if (! $app->environment('testing') || ! $directory
    || ! str_starts_with(strtolower($directory).DIRECTORY_SEPARATOR, strtolower(realpath(sys_get_temp_dir())).DIRECTORY_SEPARATOR)
    || config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== $directory.DIRECTORY_SEPARATOR.'email.sqlite') {
    http_response_code(503); exit('Isolated email fixture required');
}
config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'mailcow.provisioning_enabled' => true,
    'mailcow.contract_verified' => true, 'mailcow.write_api_key' => 'synthetic-write-key', 'mailcow.api_key' => 'synthetic-read-key']);
Http::preventStrayRequests();
Http::fake(function ($request) use ($directory) {
    if (DB::transactionLevel() !== 0) throw new RuntimeException('Synthetic Mailcow transport under transaction');
    $path = $directory.'/remote-mailboxes.json';
    $boxes = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    if (str_contains($request->url(), '/get/alias/all')) return Http::response([]);
    if (str_contains($request->url(), '/get/mailbox/')) return Http::response(str_contains($request->url(), '/all/') ? array_values($boxes) : ($boxes[rawurldecode(basename($request->url()))] ?? []));
    if (str_contains($request->url(), '/get/domain/')) return Http::response(['domain_name' => 'alrowaduni.edu.sy', 'active' => 1, 'mailboxes' => 200, 'mailboxes_in_domain' => count($boxes)]);
    $reset = str_contains($request->url(), '/edit/mailbox');
    if (! $reset && ! str_contains($request->url(), '/add/mailbox')) throw new RuntimeException('Unexpected synthetic Mailcow route');
    $address = $reset ? $request['items'][0] : $request['local_part'].'@'.$request['domain'];
    // Fake upstream persists identity/ownership only, NEVER a password or API key.
    $boxes[$address] = ['username' => $address, 'domain' => 'alrowaduni.edu.sy', 'quota' => 50 * 1048576, 'active_int' => 1,
        'attributes' => ['force_pw_update' => 1], 'tags' => $reset ? $request['attr']['tags'] : $request['tags']];
    file_put_contents($path, json_encode($boxes, JSON_THROW_ON_ERROR), LOCK_EX);
    return Http::response([['type' => 'success', 'msg' => [$reset ? 'mailbox_modified' : 'mailbox_added', $address]]]);
});
$app->handleRequest(\Illuminate\Http\Request::capture());
