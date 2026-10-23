<?php
/** Optional read-only check of an existing private report. No Laravel bootstrap or database connection. */
require dirname(__DIR__,2).'/vendor/autoload.php';
use App\Services\StudentCompletion\{CompletionData,CompletionJson};
$started=microtime(true);
if(($argv[1]??null)==='--bundle') {
    $path=$argv[2]??throw new InvalidArgumentException('Existing private bundle path required');$hash=$argv[3]??throw new InvalidArgumentException('Recorded bundle file hash required');
    $bundle=(new \App\Services\HistoricalExams\ExamSources)->load($path,$hash);
    echo json_encode(['read_only'=>true,'database_bootstrapped'=>false,'original_source_reconstruction'=>true,'bytes'=>filesize($path),
        'file_sha256'=>hash_file('sha256',$path),'bundle_contract'=>$bundle['contract'],'observations'=>count($bundle['rows']),
        'peak_php_bytes'=>memory_get_peak_usage(true),'memory_limit'=>ini_get('memory_limit'),'php'=>PHP_VERSION,'seconds'=>round(microtime(true)-$started,3)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";exit;
}
$path=$argv[1]??throw new InvalidArgumentException('Existing private report path required');$started=microtime(true);
$report=CompletionJson::file($path,['review.actions']);
if(!isset($report['review'],$report['review_hash'])||!hash_equals($report['review_hash'],CompletionData::hash($report['review'])))throw new RuntimeException('Issued report canonical review hash differs');
echo json_encode(['read_only'=>true,'database_bootstrapped'=>false,'bytes'=>filesize($path),'file_sha256'=>hash_file('sha256',$path),
    'review_contract'=>$report['review']['contract'],'actions'=>count($report['review']['actions']),'review_hash_preserved'=>true,
    'peak_php_bytes'=>memory_get_peak_usage(true),'memory_limit'=>ini_get('memory_limit'),'php'=>PHP_VERSION,'seconds'=>round(microtime(true)-$started,3)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
