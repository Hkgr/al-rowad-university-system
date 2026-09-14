<?php

/** Disposable MariaDB only. No .env, production dump, existing service or SQLite analogue. */
final class CatalogEnvironment
{
    public array $config;
    public function __construct()
    {
        $file = getenv('SCIENTIFIC_MARIADB_CONFIG') ?: '';
        $dir = realpath(dirname($file));
        if (!$dir || strcasecmp(dirname($dir), realpath(sys_get_temp_dir())) !== 0
            || !preg_match('/^pr132-catalog-[a-f0-9]{32}$/', basename($dir))) throw new RuntimeException('New task-specific temporary directory required');
        $this->config = json_decode(ltrim(file_get_contents($file), "\xEF\xBB\xBF"), true, flags: JSON_THROW_ON_ERROR);
        $c = $this->config;
        if ($c['host'] !== '127.0.0.1' || $c['port'] < 20000 || $c['database'] !== 'alrowad_uni_rust'
            || realpath($c['directory']) !== $dir || !preg_match('/^[a-f0-9]{32}$/', $c['marker'])) throw new RuntimeException('Unsafe fixture configuration');
    }
    public function connect(bool $bootstrap = false): PDO
    {
        $c = $this->config;
        $pdo = new PDO("mysql:host=127.0.0.1;port={$c['port']}".($bootstrap ? '' : ';dbname=alrowad_uni_rust').';charset=utf8mb4', $bootstrap ? 'root' : 'catalog_test', $bootstrap ? $c['root_password'] : $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $this->guard($pdo, !$bootstrap);
        return $pdo;
    }
    public function guard(PDO $pdo, bool $marker = true): void
    {
        $c = $this->config;
        $r = $pdo->query('SELECT @@version version, @@hostname hostname, @@port port, @@datadir datadir, DATABASE() db')->fetch(PDO::FETCH_ASSOC);
        if (!str_contains($r['version'], $c['version'].'-MariaDB') || strcasecmp($r['hostname'], $c['hostname']) !== 0
            || (int)$r['port'] !== $c['port'] || realpath($r['datadir']) !== realpath($c['directory'].'/data')
            || ($marker && $r['db'] !== 'alrowad_uni_rust')) throw new RuntimeException('Actual server isolation identity mismatch');
        if ($marker && $pdo->query('SELECT marker FROM catalog_test_marker')->fetchColumn() !== $c['marker']) throw new RuntimeException('Missing test-only database marker');
    }
    public function bootstrap(): void
    {
        $p = $this->connect(true);
        if ($p->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='alrowad_uni_rust'")->fetchColumn()) throw new RuntimeException('Refusing existing database');
        $p->exec('CREATE DATABASE alrowad_uni_rust CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $p->exec('CREATE TABLE alrowad_uni_rust.catalog_test_marker(marker CHAR(32) NOT NULL) ENGINE=InnoDB');
        $p->exec('INSERT INTO alrowad_uni_rust.catalog_test_marker VALUES ('.$p->quote($this->config['marker']).')');
        $p->exec("CREATE USER 'catalog_test'@'127.0.0.1' IDENTIFIED BY ".$p->quote($this->config['password']));
        // Schema-local installer/runtime test account, no global/other-schema privileges.
        $p->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, TRIGGER ON alrowad_uni_rust.* TO 'catalog_test'@'127.0.0.1'");
        $this->connect();
    }
    public function laravel(): mixed
    {
        $this->connect(); // Verify the actual server BEFORE Laravel bootstraps.
        $c = $this->config;
        foreach (['APP_ENV'=>'testing', 'APP_DEBUG'=>'false', 'APP_KEY'=>'base64:'.base64_encode(hash('sha256',$c['marker'],true)), 'DB_CONNECTION'=>'mysql', 'DB_HOST'=>'127.0.0.1', 'DB_PORT'=>(string)$c['port'], 'DB_DATABASE'=>'alrowad_uni_rust', 'DB_USERNAME'=>'catalog_test', 'DB_PASSWORD'=>$c['password'], 'DB_URL'=>'', 'DB_SOCKET'=>'', 'SESSION_DRIVER'=>'array', 'CACHE_STORE'=>'array', 'QUEUE_CONNECTION'=>'sync', 'LOG_CHANNEL'=>'stderr', 'APP_CONFIG_CACHE'=>$c['directory'].'/no-config-cache'] as $key=>$value) {
            putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
        }
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath($c['directory']);
        $app->loadEnvironmentFrom('catalog-test.no-env'); // Never read the project's .env.
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $this->guard(Illuminate\Support\Facades\DB::connection()->getPdo());
        return $app;
    }
    /** Exact checked-in statements; DELIMITER is a client directive, not server SQL. */
    public function package(PDO $p, string $name, ?string $stopAfter = null, string $package = 'scientific-course-management'): array
    {
        $this->guard($p);
        if (!in_array($name, ['00_preflight.sql','01_apply.sql','02_verify.sql'], true)) throw new RuntimeException('Package allowlist');
        if (!in_array($package, ['scientific-course-management', 'academic-program-management'], true)) throw new RuntimeException('Package directory allowlist');
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/sql/'.$package.'/'.$name);
        if (preg_match('/^\s*USE\s+(?!alrowad_uni_rust\b)/im', $sql)) throw new RuntimeException('Unexpected USE');
        $delimiter = ';'; $buffer = ''; $rows = [];
        foreach (explode("\n", $sql) as $line) {
            if (preg_match('/^DELIMITER\s+(\S+)/', trim($line), $m)) { $delimiter=$m[1]; continue; }
            if (str_starts_with(trim($line), '--') || trim($line)==='') continue;
            $buffer .= $line."\n";
            if (!str_ends_with(rtrim($buffer), $delimiter)) continue;
            $statement = substr(rtrim($buffer), 0, -strlen($delimiter)); $buffer='';
            $this->guard($p);
            $q = $p->query($statement); // Exception immediately aborts; never --force.
            do { if ($q->columnCount()) array_push($rows, ...$q->fetchAll(PDO::FETCH_ASSOC)); } while ($q->nextRowset());
            $q->closeCursor();
            if ($stopAfter && str_starts_with($statement, $stopAfter)) break;
        }
        file_put_contents($this->config['directory'].'/'.$name.($stopAfter?'.interrupted':'').'.json', json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        return $rows;
    }
}
