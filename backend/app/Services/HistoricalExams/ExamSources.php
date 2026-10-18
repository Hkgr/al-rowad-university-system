<?php

namespace App\Services\HistoricalExams;

use App\Services\StudentCompletion\{CompletionData,CompletionSnapshot};

/** OOXML cached cells/formula text only. No calculation, VBA execution or database access. */
final class ExamSources
{
    public const CONTRACT='historical-exam-completion.sources.v1';
    public function prepare(array $inputs): array
    {
        $sources=[];
        foreach(['snapshot','exam1','exam2'] as $id) {
            $in=$inputs[$id]??[];
            if(!is_file($in['path']??'')||!preg_match('/^[a-f0-9]{64}$/D',$in['sha256']??'')||!hash_equals($in['sha256'],hash_file('sha256',$in['path'])))throw new \InvalidArgumentException('Source missing or fingerprint differs: '.$id);
            $sources[$id]=['name'=>basename($in['path']),'sha256'=>$in['sha256']];
        }
        $state=(new CompletionSnapshot)->read($inputs['snapshot']['path'],true,true,true);$rows=[];$books=[];
        foreach(['exam1'=>['OutcomeofMater','supplementary'],'exam2'=>['علامات','تكميلي']] as $id=>$sheets) {
            [$cells,$metadata]=$this->workbook($inputs[$id]['path']);$books[$id]=$metadata;
            foreach($sheets as $index=>$sheet) {
                if(!isset($cells[$sheet]))throw new \InvalidArgumentException('Required examination sheet missing: '.$sheet);
                $headers=$index?['B'=>'رمز المقرر','F'=>'الرقم الجامعي','G'=>'الاسم','J'=>'الامتحان النهائي']:['E'=>'رمز المقرر','H'=>'الرقم الجامعي','I'=>'الاسم','K'=>'الامتحان النهائي','R'=>'العام الدراسي'];
                foreach($headers as $column=>$label)if(CompletionData::text($cells[$sheet][1][$column]['value']??null)!==$label)throw new \InvalidArgumentException('Examination layout changed: '.$id.'/'.$sheet.'/'.$column.'1');
                foreach($cells[$sheet] as $row=>$c) {
                    if($row<2||CompletionData::text($c[$index?'G':'I']['value']??null)==='')continue;
                    $map=$index?['term'=>'A','code'=>'B','course'=>'C','credits'=>'D','counter'=>'E','number'=>'F','name'=>'G','college'=>'H','program'=>'I','exam'=>'J']:
                        ['college'=>'B','program'=>'C','term'=>'D','code'=>'E','course'=>'F','credits'=>'G','number'=>'H','name'=>'I','coursework'=>'J','exam'=>'K','total'=>'L','display_outcome'=>'M','display_points'=>'N','condition'=>'O','counter'=>'P','supplementary'=>'Q','year'=>'R','cohort'=>'S','branch'=>'T','notes'=>'U'];
                    $values=[];foreach($map as $key=>$column)$values[$key]=$c[$column]['value']??null;
                    $rows[]=['id'=>$id.':'.$sheet.':'.$row,'reference'=>['file'=>$id,'sheet'=>$sheet,'row'=>$row,'columns'=>$map,'sha256'=>$sources[$id]['sha256']],
                        'kind'=>$index?'undated_supplementary':'regular','values'=>$values,'cells'=>$c,'row_hash'=>CompletionData::hash($c)];
                }
            }
        }
        return ['contract'=>self::CONTRACT,'interpretation'=>ExamIdentity::approvals(),'inputs'=>$inputs,'sources'=>$sources,'workbooks'=>$books,'rows'=>$rows,'snapshot'=>$state,
            'controls'=>['students'=>count($state['students']),'courses'=>count($state['courses']),'registrations'=>count($state['student_course_registrations']),'results'=>count($state['student_course_results'])]];
    }
    public function load(string $path,string $hash): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||!hash_equals($hash,hash_file('sha256',$path)))throw new \InvalidArgumentException('Bundle fingerprint differs.');
        $b=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
        if(($b['contract']??null)!==self::CONTRACT||!hash_equals(CompletionData::hash($b),CompletionData::hash($this->prepare($b['inputs']))))throw new \InvalidArgumentException('Bundle/source interpretation changed; prepare a new bundle/review/UUID.');
        $b['artifact_file_sha256']=$hash;return $b;
    }
    private function xml(string $text): \SimpleXMLElement
    {
        $xml=simplexml_load_string($text,options:LIBXML_NONET|LIBXML_NOCDATA);
        if(!$xml)throw new \InvalidArgumentException('Invalid source XML.');return $xml;
    }
    private function workbook(string $path): array
    {
        $zip=new \ZipArchive;if($zip->open($path)!==true)throw new \InvalidArgumentException('Invalid workbook.');
        try {
            $strings=[];$text=$zip->getFromName('xl/sharedStrings.xml');
            if($text!==false)foreach($this->xml($text)->si as $si){$s='';foreach($si->xpath('.//*[local-name()="t"]') as $t)$s.=(string)$t;$strings[]=$s;}
            $rels=[];foreach($this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'))->Relationship as $r)$rels[(string)$r['Id']]='xl/'.ltrim((string)$r['Target'],'/');
            $out=[];$formulaCount=0;$formulaSamples=[];
            foreach($this->xml($zip->getFromName('xl/workbook.xml'))->sheets->sheet as $s) {
                $name=(string)$s['name'];$rid=(string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target=$rels[$rid];$target=str_replace('xl/xl/','xl/',$target);$text=$zip->getFromName($target);if($text===false)throw new \InvalidArgumentException('Missing workbook sheet XML.');
                $reader=new \XMLReader;$reader->XML($text,null,LIBXML_NONET|LIBXML_COMPACT);
                while($reader->read())if($reader->nodeType===\XMLReader::ELEMENT&&$reader->localName==='row') {
                    $row=$this->xml($reader->readOuterXml());$n=(int)$row['r'];
                    foreach($row->c as $c) {
                        $address=(string)$c['r'];$col=preg_replace('/\d/','',$address);$type=(string)$c['t'];$v=isset($c->v)?(string)$c->v:null;
                        $value=match($type){'s'=>$v===null?null:($strings[(int)$v]??throw new \InvalidArgumentException('Invalid shared string.')),'inlineStr'=>(string)$c->is->t,'b'=>$v==='1',default=>$v};
                        $cell=['value'=>$value];if(isset($c->f)){$cell['formula']=(string)$c->f;$formulaCount++;if(count($formulaSamples)<30)$formulaSamples[]=['sheet'=>$name,'cell'=>$address,'text'=>(string)$c->f,'cached'=>$value];}
                        if($value!==null||isset($c->f))$out[$name][$n][$col]=$cell;
                    }
                }$reader->close();
            }
            $vba=$zip->getFromName('xl/vbaProject.bin');
            return [$out,['formula_count'=>$formulaCount,'formula_samples'=>$formulaSamples,'vba_sha256'=>$vba===false?null:hash('sha256',$vba),'vba_modules'=>$vba===false?[]:(new ExamVbaEvidence)->extract($vba)]];
        } finally {$zip->close();}
    }
}
