<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\HistoricalExams\{ExamSources,ExamProjection,ExamCompletionService};
use App\Services\StudentCompletion\{CompletionData,CompletionFiles,CompletionJson};
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
                if($directory=$this->option('handoff'))$inputs['handoff']=['path'=>$directory];
                $bundle=app(ExamSources::class)->prepare($inputs);CompletionFiles::write($output,$bundle);
                $result=(new ExamProjection)->build($bundle,$bundle['snapshot'],'offline_snapshot_not_apply_authorization');$result['review_available']=false;
                CompletionFiles::write($output.'.snapshot-report.json',$result);
                $this->line(CompletionData::json(['bundle'=>$output,'bundle_hash'=>hash_file('sha256',$output),'snapshot_report'=>$output.'.snapshot-report.json','counts'=>$result['counts'],'database_connected'=>false]));return 0;
            }
            $actor=User::findOrFail($this->required('actor'));$service=app(ExamCompletionService::class);
            $catalog=$this->getDefinition()->hasOption('catalog-credits') && $this->option('catalog-credits');
            $catalogService=app(\App\Services\HistoricalExams\ExamCatalogCreditService::class);
            $recovery=$this->getDefinition()->hasOption('recovery-check') && $this->option('recovery-check');
            if($recovery&&$catalog)throw new \InvalidArgumentException('--recovery-check is for examination completion, not catalog-credit operations.');
            if($this->operation==='status')$result=$catalog?$catalogService->status($actor,$this->required('operation-id')):($recovery?$service->recoveryCheck($actor,$this->required('operation-id')):$service->status($actor,$this->required('operation-id')));
            else {
                $bundle=app(ExamSources::class)->load($this->required('bundle'),$this->required('bundle-hash'));
                $result=match($this->operation){'preview'=>$catalog?$catalogService->preview($actor,$bundle,$this->option('program-code')):$service->preview($actor,$bundle),'verify'=>$catalog?$catalogService->status($actor,$this->required('operation-id'),$bundle):$service->verify($actor,$bundle,$this->required('operation-id')),
                    'apply'=>($catalog?$catalogService:$service)->apply($actor,$bundle,CompletionJson::file($this->required('review')),$this->required('review-hash'),$this->required('operation-id'),$this->required('expected-revision'),(bool)$this->option('resume')),
                    'review-correction'=>app(\App\Services\HistoricalExams\HistoricalExamWorkService::class)->reviewCorrection($actor,$bundle,$this->required('work-item')),
                    'adopt-correction'=>$this->option('confirmed')?app(\App\Services\HistoricalExams\HistoricalExamWorkService::class)->adopt($actor,$bundle,
                        CompletionJson::file($this->required('review')),$this->required('review-hash'),$this->required('operation-id'),$this->required('expected-revision'),$this->required('reason')):
                        throw new \InvalidArgumentException('--confirmed is required for explicit correction adoption.')};
            }
            CompletionFiles::write($output,$result);if(in_array($this->operation,['preview','review-correction'],true))CompletionFiles::write($output.'.review.json',$result['review']);
            $this->line(CompletionData::json($recovery?$result:['output'=>$output,'status'=>$result['status'],'review_hash'=>$result['review_hash']??null,'counts'=>$result['counts']??null]));
            return in_array($result['status'],['PARTIAL','BLOCKED','INTERRUPTED'],true)?2:0;
        } catch(\Throwable $e) {$this->error(CompletionData::json(['error_code'=>$e->errorCode??'historical_exam_failed','message'=>$e->getMessage(),'note'=>'Do not infer rollback after a lost response. Inspect status; never retry a write automatically.']));return 1;}
    }
}
