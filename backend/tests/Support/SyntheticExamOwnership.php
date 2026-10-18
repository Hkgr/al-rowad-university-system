<?php
namespace Tests\Support;
use App\Services\HistoricalExams\ExamIdentity;
use App\Services\StudentCompletion\CompletionData as D;

/** Same approved-rule shapes/row references, synthetic people only. No runtime policy override API. */
final class SyntheticExamOwnership
{
    public static function fixture(): array
    {
        $p=ExamIdentity::approvals();$original=$p;$map=[];$students=[];
        $hashes=array_unique([...array_keys($p['target_name_hashes']),$p['missing_number']['target_hash']]);
        foreach(array_values($hashes) as $i=>$hash) {
            $s=['student_id'=>$i+1,'student_number'=>'SYN-TARGET-'.$i,'first_name'=>$i===3?'Synthetic Compound':'Synthetic','last_name'=>'Owner'.$i,'father_name'=>'Parent','academic_program_id'=>1,'deleted_at'=>null];
            $map[$hash]=$s;$students[]=$s;
        }
        $targets=[];foreach($map as $s)$targets[D::hash($s['student_number'])]=D::hash([$s['first_name'],$s['last_name']]);$p['target_name_hashes']=$targets;$cases=['number'=>[],'row'=>[]];
        foreach($p['number_person_rules'] as $i=>&$rule) {
            $s=$map[$rule['target_hash']];$source='SYN-SOURCE-'.$i;$labels=[];
            foreach($rule['source_pair_hashes'] as $j=>$hash)$labels[]='Synthetic approved source '.$i.' variant '.$j;
            $rule['source_pair_hashes']=array_map(fn($label)=>D::hash([$source,$label]),$labels);$rule['target_hash']=D::hash($s['student_number']);
            $cases['number'][$i]=['source'=>$source,'labels'=>$labels,'target'=>$s];
        }unset($rule);
        foreach($p['row_rules'] as $i=>&$rule) {
            $s=$map[$rule['target_hash']];$source='SYN-ROW-SOURCE-'.$i;$name='Synthetic row owner '.$i;
            $rule['source_name_hash']=D::hash($name);$rule['target_hash']=D::hash($s['student_number']);if(isset($rule['source_number_hash']))$rule['source_number_hash']=D::hash($source);
            $cases['row'][$i]=['source'=>$source,'labels'=>[$name],'target'=>$s];
        }unset($rule);
        $s=$map[$original['missing_number']['target_hash']];$p['missing_number']['target_hash']=D::hash($s['student_number']);$p['missing_number']['target_identity_hash']=D::hash([$s['first_name'],$s['last_name'],$s['father_name']]);$p['missing_number']['source_name_hashes']=[D::hash('Synthetic missing candidate'),D::hash('Synthetic candidate')];$cases['missing']=['target'=>$s];
        $s=$map[$original['separation']['target_hash']];$p['separation']=['source_number_hash'=>D::hash('SYN-ORIGINAL-OWNER'),'source_name_hashes'=>[D::hash('Synthetic qualified person')],'target_hash'=>D::hash($s['student_number'])];$cases['separation']=[['source'=>'SYN-ORIGINAL-OWNER','labels'=>['Synthetic qualified person'],'target'=>$s]];
        $resolver=new ExamIdentity;(new \ReflectionProperty(ExamIdentity::class,'policy'))->setValue($resolver,$p);
        return ['resolver'=>$resolver,'policy'=>$p,'cases'=>$cases,'students'=>$students];
    }
}
