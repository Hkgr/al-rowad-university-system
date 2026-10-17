<?php

namespace App\Services\StudentCompletion;

final class ConsolidatedCompletionSources
{
    public function prepare(array $input): array
    {
        foreach(['handoff','snapshot'] as $key)if(!is_file($input[$key]['path']??'')||!hash_equals($input[$key]['sha256']??'',hash_file('sha256',$input[$key]['path'])))throw new \InvalidArgumentException('Consolidated executable input fingerprint differs: '.$key);
        if(($input['approval_profile']??null)!=='approved-followup-2026-10-17')throw new \InvalidArgumentException('Unsupported explicit approval profile.');
        $revised=($input['contract']??null)==='student-completion.consolidated-inputs.v2';$contract=$revised?'student-completion.sources.v5':'student-completion.sources.v4';
        $reader=new ConsolidatedHandoff;$reader->open($input['handoff']['path']);$rows=(new ConsolidatedCompletionRows)->read($reader);
        $sources=[];foreach($rows['original_sources'] as $id=>$s)$sources[$id]=['name'=>$s['original_filename'],'sha256'=>$s['original_sha256'],'verification'=>'recorded_provenance_not_independently_rehashed'];
        foreach(['handoff','snapshot'] as $key)$sources[$key]=['name'=>basename($input[$key]['path']),'sha256'=>$input[$key]['sha256'],'verification'=>'actual_executable_input_rehashed'];
        $catalog=new ConsolidatedCompletionCatalog;
        // Regenerate derived configuration; a supplied private configuration cannot replace it.
        $config=array_intersect_key($input,array_flip(['contract','handoff','snapshot','approval_profile']));
        $config['program_aliases']=$catalog->aliases();$config['college_aliases']=$catalog->collegeAliases();
        $bundle=['contract'=>$contract,'config'=>$config,'sources'=>$sources,
            'snapshot'=>(new CompletionSnapshot)->read($input['snapshot']['path'],true,$revised),
            'identities'=>$rows['identities'],'observations'=>$rows['observations'],'conflicts'=>$rows['conflicts'],'unidentified_source_rows'=>$rows['unidentified_source_rows']];
        $catalogConfig=$catalog->configure($bundle,$rows['tables'],$rows['catalog_evidence'],$revised?\App\Services\CatalogCompletion\FollowupCourseIdentity::REVISION:null);$evidence=$catalogConfig['evidence'];unset($catalogConfig['evidence']);
        $bundle['config']=(new ConsolidatedCompletionDecisions)->configure($bundle,$rows['tables']);
        $bundle['config']['followup']=['policy'=>'approved_missing_students_2026_10','handoff'=>$input['handoff'],
            'decision_ids'=>array_column(array_filter($rows['tables']['Decisions'],fn($d)=>in_array($d['field'],['status_default','enrollment_date_fallback','enrollment_date','identity','academic_program','student_status','accepted_label','source_owner'],true)),'decision_id'),
            'owner_record_ids'=>array_column($rows['tables']['ExamOwners'],'record_id'),'table_hashes'=>['Decisions'=>CompletionData::hash($rows['tables']['Decisions']),'ExamOwners'=>CompletionData::hash($rows['tables']['ExamOwners'])],'catalog'=>$catalogConfig];
        $bundle=(new CompletionResolutions)->compile($bundle);$bundle=(new CompletionFollowup)->compile($bundle,$rows['tables']);
        $bundle['catalog_followup']=(new \App\Services\CatalogCompletion\ApprovedFollowupCatalog)->compile($bundle,$rows['tables']+['SourceRows'=>$evidence]);
        $bundle['contract']=$contract;$bundle['consolidated_extraction']=$rows['extraction'];
        $bundle['consolidated_provenance']=['contract'=>'alrowad.student-completion.handoff.v1','originals_required'=>false,'original_sources'=>$rows['original_sources'],
            'executable_input_hashes'=>['handoff'=>$input['handoff']['sha256'],'snapshot'=>$input['snapshot']['sha256']]];
        return $bundle;
    }
}
