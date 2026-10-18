<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\HistoricalExams\{ExamSources,ExamProjection,ExamCompletionService};
use App\Services\StudentCompletion\{CompletionData,CompletionFiles};
use Illuminate\Console\Command;

/** Local preparation never resolves an actor or database-backed service. All artifacts are private/immutable. */
abstract class HistoricalExamCommand extends Command
{
    protected string $operation;
    private function required(string $key): string {$v=$this->option($key);if(!is_string($v)||trim($v)==='')throw new \InvalidArgumentException('--'.$key.' required');return $v;}
    public function handle(): int
    {
        try {
            $output=$this->required('output');
            if($this->operation==='prepare') {
                $inputs=[];foreach(['snapshot','exam1','exam2'] as $k)$inputs[$k]=['path'=>$this->required($k),'sha256'=>$this->required($k.'-hash')];
                $bundle=(new ExamSources)->prepare($inputs);CompletionFiles::write($output,$bundle);
                $result=(new ExamProjection)->build($bundle,$bundle['snapshot'],'offline_snapshot_not_apply_authorization');$result['review_available']=false;
                CompletionFiles::write($output.'.snapshot-report.json',$result);
                $this->line(CompletionData::json(['bundle'=>$output,'bundle_hash'=>hash_file('sha256',$output),'snapshot_report'=>$output.'.snapshot-report.json','counts'=>$result['counts'],'database_connected'=>false]));return 0;
            }
            $actor=User::findOrFail($this->required('actor'));$service=app(ExamCompletionService::class);
            if($this->operation==='status')$result=$service->status($actor,$this->required('operation-id'));
            else {
                $bundle=(new ExamSources)->load($this->required('bundle'),$this->required('bundle-hash'));
                $result=match($this->operation){'preview'=>$service->preview($actor,$bundle),'verify'=>$service->verify($actor,$bundle,$this->required('operation-id')),
                    'apply'=>$service->apply($actor,$bundle,json_decode(file_get_contents($this->required('review')),true,flags:JSON_THROW_ON_ERROR),$this->required('review-hash'),$this->required('operation-id'),$this->required('expected-revision'),(bool)$this->option('resume'))};
            }
            CompletionFiles::write($output,$result);if($this->operation==='preview')CompletionFiles::write($output.'.review.json',$result['review']);
            $this->line(CompletionData::json(['output'=>$output,'status'=>$result['status'],'review_hash'=>$result['review_hash']??null,'counts'=>$result['counts']??null]));
            return in_array($result['status'],['PARTIAL','BLOCKED','INTERRUPTED'],true)?2:0;
        } catch(\Throwable $e) {$this->error(CompletionData::json(['error_code'=>$e->errorCode??'historical_exam_failed','message'=>$e->getMessage(),'note'=>'Do not infer rollback after a lost response. Inspect status; never retry a write automatically.']));return 1;}
    }
}
