<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StudentCompletion\{CompletionData,CompletionFiles,CompletionProjection,CompletionSources,StudentCompletionService};
use Illuminate\Console\Command;

/** No web route. Preparation deliberately does not resolve a database-backed service or actor. */
abstract class StudentCompletionCommand extends Command
{
    protected string $operation;
    public function handle(): int
    {
        try {
            if ($this->operation==='prepare') {
                if($this->option('handoff')||$this->option('snapshot')) {
                    if($this->option('sources'))throw new \InvalidArgumentException('Choose consolidated inputs or the original configuration, not both.');
                    $config=['contract'=>'student-completion.consolidated-inputs.v2','approval_profile'=>'approved-followup-2026-10-17'];
                    foreach(['handoff','snapshot'] as $key){$path=$this->required($key);if(!is_file($path))throw new \InvalidArgumentException('Input missing: '.$key);$config[$key]=['path'=>$path,'sha256'=>hash_file('sha256',$path)];}
                } else $config=json_decode(file_get_contents($this->required('sources')),true,flags:JSON_THROW_ON_ERROR);
                $bundle=app(CompletionSources::class)->prepare($config);
                $file=$this->required('output');CompletionFiles::write($file,$bundle);
                $report=app(CompletionProjection::class)->build($bundle,$bundle['snapshot'],'offline_snapshot_not_apply_authorization');
                $report['review_available']=false;
                CompletionFiles::write($file.'.report.json',$report);
                $result=['bundle'=>$file,'bundle_hash'=>hash_file('sha256',$file),'offline_report'=>$file.'.report.json','counts'=>$report['counts'],'database_connected'=>false];
            } else {
                $actor=User::findOrFail($this->required('actor'));$service=app(StudentCompletionService::class);
                if ($this->operation==='status') $result=$service->status($actor,$this->required('operation-id'));
                else {
                    $bundle=app(CompletionSources::class)->load($this->required('bundle'),$this->required('bundle-hash'));
                    $result=match($this->operation) {
                        'preview'=>$service->preview($actor,$bundle),
                        'verify'=>$service->verify($actor,$bundle,$this->required('operation-id')),
                        'apply'=>$service->apply($actor,$bundle,json_decode(file_get_contents($this->required('review')),true,flags:JSON_THROW_ON_ERROR),
                            $this->required('review-hash'),$this->required('operation-id'),$this->required('expected-revision'),(bool)$this->option('resume')),
                    };
                }
                if ($this->option('output')) {
                    CompletionFiles::write($this->option('output'),$result);
                    if ($this->operation==='preview') CompletionFiles::write($this->option('output').'.review.json',$result['review']);
                    $this->line(CompletionData::json(['output'=>$this->option('output'),'review_hash'=>$result['review_hash']??null,'status'=>$result['status']??null]));
                    return in_array($result['status']??null,['PARTIAL','BLOCKED','INTERRUPTED'],true)?2:0;
                }
            }
            $this->line(CompletionData::json($result));
            return in_array($result['status']??null,['PARTIAL','BLOCKED','INTERRUPTED'],true)?2:0;
        } catch(\Throwable $e) {
            $this->error(CompletionData::json(['error_code'=>$e->errorCode??'student_completion_failed','message'=>$e->getMessage(),
                'note'=>'Do not infer rollback or retry a disconnected write. Read operation status.']));
            return 1;
        }
    }
    private function required(string $name): string
    {
        $value=$this->option($name);if(!is_string($value)||trim($value)==='')throw new \InvalidArgumentException('--'.$name.' is required.');return $value;
    }
}
