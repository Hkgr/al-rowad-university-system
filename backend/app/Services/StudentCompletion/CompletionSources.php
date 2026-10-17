<?php

namespace App\Services\StudentCompletion;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/** Private, reproducible source preparation. Reads cached cells only; never executes VBA/formulas. */
final class CompletionSources
{
    public function prepare(array $config): array
    {
        if(($config['contract']??null)==='student-completion.consolidated-inputs.v1')return (new ConsolidatedCompletionSources)->prepare($config);
        foreach (['snapshot','roster','conflicts','examinations'] as $key)
            if (empty($config[$key])) throw new \InvalidArgumentException('Missing source: '.$key);
        $sources = []; $identities = []; $observations = []; $conflicts = []; $unidentified=[];
        $snapshot = $this->source($config['snapshot'], 'snapshot', $sources);
        $tables = (new CompletionSnapshot)->read($snapshot['path'], isset($config['followup']));
        $roster = $this->source($config['roster'], 'roster', $sources);
        // Explicitly documented template example, not an arbitrary first data row.
        $skip = $roster['example_rows'] ?? [];
        foreach($this->sheets($roster['path'],$roster['sheet']??'بيانات الطلاب',4,['A','B','C','D','N','O','R','S']) as [$sheet,$start,$end]) {
        $this->headers($sheet,['A3'=>'الرقم الجامعي','B3'=>'الاسم الأول','C3'=>'الكنية','O3'=>'القسم','S3'=>'تاريخ التسجيل']);
        for ($i = $start; $i <= $end; $i++) {
            if (in_array($i, $skip, true)) continue;
            $number = CompletionData::text($this->cell($sheet, 'A'.$i));
            if ($number === '') {
                $name=CompletionData::text($this->cell($sheet,'B'.$i).' '.$this->cell($sheet,'C'.$i));
                if($name!=='')$unidentified[]=['reference'=>$this->ref('roster',$sheet->getTitle(),$i),'name'=>$name,'blocker'=>'student_number_missing'];
                continue;
            }
            $identities[] = ['number'=>$number,'first_name'=>CompletionData::text($this->cell($sheet,'B'.$i)),
                'last_name'=>CompletionData::text($this->cell($sheet,'C'.$i)), 'father_name'=>CompletionData::text($this->cell($sheet,'D'.$i)),
                'full_name'=>CompletionData::text($this->cell($sheet,'B'.$i).' '.$this->cell($sheet,'C'.$i)),
                'program'=>CompletionData::text($this->cell($sheet,'O'.$i)), 'college'=>CompletionData::text($this->cell($sheet,'N'.$i)), 'enrollment_date'=>$this->dateCell($sheet,'S'.$i),
                'cohort'=>$this->cell($sheet,'R'.$i),'status'=>null,'reference'=>$this->ref('roster',$sheet->getTitle(),$i)];
            $identities[array_key_last($identities)]['original']=['number'=>$this->cell($sheet,'A'.$i),'first_name'=>$this->cell($sheet,'B'.$i),
                'last_name'=>$this->cell($sheet,'C'.$i),'father_name'=>$this->cell($sheet,'D'.$i),'program'=>$this->cell($sheet,'O'.$i),'enrollment_date'=>$this->cell($sheet,'S'.$i)];
        }
        }
        foreach ($config['examinations'] as $index => $input) {
            $id = 'exam'.($index+1); $source = $this->source($input, $id, $sources);
            foreach ($source['regular_sheets'] as $name) {
                foreach($this->sheets($source['path'],$name,2,['B','C','D','E','F','H','I','K','M','P','Q','R','S']) as [$sheet,$start,$end]) {
                $this->headers($sheet,['H1'=>'الرقم الجامعي','I1'=>'الاسم','E1'=>'رمز المقرر','K1'=>'الامتحان النهائي','R1'=>'العام الدراسي']);
                for ($i=$start; $i<=$end; $i++) {
                    $number=CompletionData::text($this->cell($sheet,'H'.$i));
                    if ($number==='') {
                        $unidentifiedName=CompletionData::text($this->cell($sheet,'I'.$i));
                        if($unidentifiedName!=='')$unidentified[]=['reference'=>$this->ref($id,$name,$i),'name'=>$unidentifiedName,'blocker'=>'student_number_missing'];
                        continue;
                    }
                    $reference=$this->ref($id,$name,$i);
                    $observations[]=['number'=>$number,'name'=>CompletionData::text($this->cell($sheet,'I'.$i)),
                        'program'=>CompletionData::text($this->cell($sheet,'C'.$i)), 'college'=>CompletionData::text($this->cell($sheet,'B'.$i)), 'course_code'=>CompletionData::text($this->cell($sheet,'E'.$i)),
                        'course_name'=>CompletionData::text($this->cell($sheet,'F'.$i)), 'plan_term'=>$this->cell($sheet,'D'.$i),
                        'year'=>$this->cell($sheet,'R'.$i),'cohort'=>$this->cell($sheet,'S'.$i),'attempt'=>$this->cell($sheet,'P'.$i),
                        'exam_mark'=>$this->cell($sheet,'K'.$i),'outcome'=>$this->cell($sheet,'M'.$i),
                        'supplementary'=>$this->cell($sheet,'Q'.$i),'kind'=>'regular','reference'=>$reference];
                    $observations[array_key_last($observations)]['original']=['number'=>$this->cell($sheet,'H'.$i),'name'=>$this->cell($sheet,'I'.$i),
                        'program'=>$this->cell($sheet,'C'.$i),'course_code'=>$this->cell($sheet,'E'.$i),'course_name'=>$this->cell($sheet,'F'.$i)];
                }
                }
            }
            foreach ($source['supplementary_sheets'] ?? [] as $name) {
                foreach($this->sheets($source['path'],$name,2,['A','B','C','E','F','G','H','I','J']) as [$sheet,$start,$end]) {
                $this->headers($sheet,['F1'=>'الرقم الجامعي','G1'=>'الاسم','B1'=>'رمز المقرر','J1'=>'الامتحان النهائي']);
                for ($i=$start;$i<=$end;$i++) {
                    $number=CompletionData::text($this->cell($sheet,'F'.$i));
                    if ($number==='') {
                        $unidentifiedName=CompletionData::text($this->cell($sheet,'G'.$i));
                        if($unidentifiedName!=='')$unidentified[]=['reference'=>$this->ref($id,$name,$i),'name'=>$unidentifiedName,'blocker'=>'student_number_missing'];
                        continue;
                    }
                    $observations[]=['number'=>$number,'name'=>CompletionData::text($this->cell($sheet,'G'.$i)),
                        'program'=>CompletionData::text($this->cell($sheet,'I'.$i)), 'college'=>CompletionData::text($this->cell($sheet,'H'.$i)), 'course_code'=>CompletionData::text($this->cell($sheet,'B'.$i)),
                        'course_name'=>CompletionData::text($this->cell($sheet,'C'.$i)), 'plan_term'=>$this->cell($sheet,'A'.$i),
                        'year'=>null,'cohort'=>null,'attempt'=>$this->cell($sheet,'E'.$i),'exam_mark'=>$this->cell($sheet,'J'.$i),
                        'outcome'=>null,'supplementary'=>true,'kind'=>'undated_supplementary','reference'=>$this->ref($id,$name,$i)];
                    $observations[array_key_last($observations)]['original']=['number'=>$this->cell($sheet,'F'.$i),'name'=>$this->cell($sheet,'G'.$i),
                        'program'=>$this->cell($sheet,'I'.$i),'course_code'=>$this->cell($sheet,'B'.$i),'course_name'=>$this->cell($sheet,'C'.$i)];
                }
                }
            }
            foreach($source['identity_sheets']??(!empty($source['identity_sheet'])?[$source['identity_sheet']]:[]) as $identitySheet) {
                foreach($this->sheets($source['path'],$identitySheet,2,['C','D','L','P','Q','R','S']) as [$sheet,$start,$end]) {
                $this->headers($sheet,['P1'=>'الرقم الجامعي','C1'=>'الاسم والكنية','L1'=>'تاريخ التسجيل']);
                for ($i=$start;$i<=$end;$i++) {
                    $number=CompletionData::text($this->cell($sheet,'P'.$i));
                    if ($number==='') {
                        $unidentifiedName=CompletionData::text($this->cell($sheet,'C'.$i));
                        if($unidentifiedName!=='')$unidentified[]=['reference'=>$this->ref($id,$sheet->getTitle(),$i),'name'=>$unidentifiedName,'blocker'=>'student_number_missing'];
                        continue;
                    }
                    $identities[]=['number'=>$number,'full_name'=>CompletionData::text($this->cell($sheet,'C'.$i)),
                        'first_name'=>null,'last_name'=>null,'father_name'=>CompletionData::text($this->cell($sheet,'D'.$i)),
                        'program'=>CompletionData::text($this->cell($sheet,'R'.$i)), 'college'=>CompletionData::text($this->cell($sheet,'Q'.$i)), 'enrollment_date'=>$this->dateCell($sheet,'L'.$i),
                        'cohort'=>null,'status'=>$identitySheet==='Data'&&$this->cell($sheet,'S'.$i)===true ? 'active' : null,
                        'source_role'=>$identitySheet==='Data'?'identity_master':'display_copy',
                        'reference'=>$this->ref($id,$sheet->getTitle(),$i)];
                    $identities[array_key_last($identities)]['original']=['number'=>$this->cell($sheet,'P'.$i),'full_name'=>$this->cell($sheet,'C'.$i),
                        'father_name'=>$this->cell($sheet,'D'.$i),'program'=>$this->cell($sheet,'R'.$i),'enrollment_date'=>$this->cell($sheet,'L'.$i)];
                }
                }
            }
        }
        $source=$this->source($config['conflicts'],'conflicts',$sources);
        foreach($this->sheets($source['path'],$source['sheet']??'مراجعة بيانات الطلاب',6,['A','B','C','D','E']) as [$sheet,$start,$end]) {
        $this->headers($sheet,['A5'=>'الرقم الجامعي','D5'=>'المشكلة','E5'=>'حل المشكلة']);
        if(isset($config['resolution_policy']))$this->headers($sheet,['B5'=>'ملفات شؤون الامتحانات','C5'=>'ملف شؤون الطلاب']);
        for ($i=$start;$i<=$end;$i++) {
            $number=CompletionData::text($this->cell($sheet,'A'.$i)); if ($number==='') continue;
            $conflicts[]=['number'=>$number,'problem'=>$this->cell($sheet,'D'.$i),'solution'=>$this->cell($sheet,'E'.$i),
                'reference'=>$this->ref('conflicts',$sheet->getTitle(),$i),'cells'=>array_combine(['A','B','C','D','E'],array_map(fn($c)=>$this->cell($sheet,$c.$i),['A','B','C','D','E']))];
        }
        }
        $handoff=null;
        if(isset($config['followup'])) {
            $input=$this->source($config['followup']['handoff'],'handoff',$sources);
            $handoff=$this->handoff($input['path'], $config['followup']['catalog']['evidence_record_ids']??[]);
        }
        $bundle=(new CompletionResolutions)->compile(['contract'=>isset($config['resolution_policy'])?'student-completion.sources.v2':'student-completion.sources.v1','config'=>$config,'sources'=>$sources,
            'identities'=>$identities,'observations'=>$observations,'conflicts'=>$conflicts,'unidentified_source_rows'=>$unidentified,'snapshot'=>$tables]);
        if($handoff===null)return $bundle;
        $bundle=(new CompletionFollowup)->compile($bundle,$handoff);
        if(isset($config['followup']['catalog']))$bundle['catalog_followup']=(new \App\Services\CatalogCompletion\ApprovedFollowupCatalog)->compile($bundle,$handoff);
        return $bundle;
    }

    public function load(string $path, string $expectedHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/',$expectedHash) || !hash_equals($expectedHash,hash_file('sha256',$path)))
            throw new \InvalidArgumentException('Source bundle fingerprint differs.');
        $bundle=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
        if (!in_array($bundle['contract']??null,['student-completion.sources.v1','student-completion.sources.v2','student-completion.sources.v3','student-completion.sources.v4'],true)) throw new \InvalidArgumentException('Unsupported source contract.');
        $fresh=$this->prepare($bundle['config']);
        // Preserve read/replay of genuine pre-resolution bundles. Never strip arbitrary fields.
        if($bundle['contract']==='student-completion.sources.v1'&&!isset($bundle['config']['resolution_policy'])) {
            if(isset($bundle['conflicts'][0])&&!isset($bundle['conflicts'][0]['cells']))foreach($fresh['conflicts'] as &$conflict)unset($conflict['cells']);unset($conflict);
            foreach(['grade_approvals','approval_statuses'] as $table)if(!array_key_exists($table,$bundle['snapshot']))unset($fresh['snapshot'][$table]);
        }
        if (!hash_equals(CompletionData::hash($bundle),CompletionData::hash($fresh))) throw new \InvalidArgumentException('Source data or approved decisions changed. Prepare/review again.');
        return $bundle;
    }

    private function source(array $input,string $id,array &$sources): array
    {
        if (!is_file($input['path']??'') || !is_readable($input['path'])) throw new \InvalidArgumentException('Unreadable source: '.$id);
        $hash=hash_file('sha256',$input['path']);
        if (!hash_equals($input['sha256']??'', $hash)) throw new \InvalidArgumentException('Source fingerprint differs: '.$id);
        $sources[$id]=['name'=>basename($input['path']),'sha256'=>$hash]; return $input;
    }
    private function handoff(string $path,array $evidenceIds=[]): array
    {
        $out=[];
        foreach(['Decisions'=>'decision_id','ExamOwners'=>'record_id','Programs'=>'program_key','Curriculum'=>'record_id','CourseMappings'=>'program_key'] as $name=>$first) {
            $reader=new Xlsx;$reader->setReadDataOnly(true)->setLoadSheetsOnly([$name]);$book=$reader->load($path);
            try {
                $sheet=$book->getSheetByName($name);if(!$sheet||$this->cell($sheet,'A1')!==$first)throw new \InvalidArgumentException('Invalid handoff table: '.$name);
                $headers=[];foreach($sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1',null,false,false)[0] as $header)$headers[]=$header;
                if(count($headers)!==count(array_unique($headers)))throw new \InvalidArgumentException('Duplicate handoff headers.');
                $rows=[];foreach($sheet->rangeToArray('A2:'.$sheet->getHighestDataColumn().$sheet->getHighestDataRow(),null,false,false) as $i=>$values) {
                    if($values[0]===null||$values[0]==='')continue;
                    $row=array_combine($headers,$values);$row['_row']=$i+2;$rows[]=$row;
                }
                $out[$name]=$rows;
            } finally {$book->disconnectWorksheets();}
        }
        if($evidenceIds) {
            // First locate exact evidence references using only the identifier column.
            $filter=new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function readCell(string $columnAddress,int $row,string $worksheetName=''): bool {return $columnAddress==='A';}
            };
            $reader=new Xlsx;$reader->setReadDataOnly(true)->setLoadSheetsOnly(['SourceRows'])->setReadFilter($filter);
            $book=$reader->load($path);$sheet=$book->getSheetByName('SourceRows');$selected=[1];
            foreach($sheet->getRowIterator(2) as $row)if(in_array($this->cell($sheet,'A'.$row->getRowIndex()),$evidenceIds,true))$selected[]=$row->getRowIndex();
            $book->disconnectWorksheets();
            if(count($selected)!==count($evidenceIds)+1)throw new \InvalidArgumentException('Required extracted evidence is missing or duplicated.');
            $filter=new class($selected) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function __construct(private array $rows){}
                public function readCell(string $columnAddress,int $row,string $worksheetName=''): bool {return in_array($row,$this->rows,true);}
            };
            $reader=new Xlsx;$reader->setReadDataOnly(true)->setLoadSheetsOnly(['SourceRows'])->setReadFilter($filter);$book=$reader->load($path);
            try {
                $sheet=$book->getSheetByName('SourceRows');$headers=$sheet->rangeToArray('A1:H1',null,false,false)[0];$rows=[];
                foreach(array_slice($selected,1) as $i){$r=array_combine($headers,$sheet->rangeToArray('A'.$i.':H'.$i,null,false,false)[0]);$r['_row']=$i;$rows[]=$r;}
                $out['SourceRows']=$rows;
            } finally {$book->disconnectWorksheets();}
        }
        return $out;
    }
    private function sheets(string $path,string $name,int $first,array $columns): \Generator
    {
        // listWorksheetInfo assumes every sheet is a worksheet and fails on real Excel 4 macro sheets.
        // Inspect ONLY the selected worksheet relationship; never load/execute a macro sheet.
        $last=$this->lastRow($path,$name,$columns);
        for($start=$first;$start<=max($first,$last);$start+=250) {
            $end=min($start+249,max($first,$last));
            $filter=new class($start,$end,$columns) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function __construct(private int $start,private int $end,private array $columns){}
                public function readCell(string $columnAddress,int $row,string $worksheetName=''): bool
                {return in_array($columnAddress,$this->columns,true)&&($row<=5||($row>=$this->start&&$row<=$this->end));}
            };
            $reader=new Xlsx;$reader->setReadDataOnly(true)->setReadEmptyCells(false)->setLoadSheetsOnly([$name])->setReadFilter($filter);
            $book=$reader->load($path);$sheet=$book->getSheetByName($name);
            if(!$sheet)throw new \InvalidArgumentException('Required source sheet missing: '.$name);
            try {yield [$sheet,$start,$end];} finally {$book->disconnectWorksheets();unset($sheet,$book,$reader);gc_collect_cycles();}
        }
    }
    private function lastRow(string $path,string $name,array $columns): int
    {
        $zip=new \ZipArchive;if($zip->open($path)!==true)throw new \InvalidArgumentException('Invalid workbook archive.');
        try {
            $book=$zip->getFromName('xl/workbook.xml');$rels=$zip->getFromName('xl/_rels/workbook.xml.rels');
            if($book===false||$rels===false||str_contains($book,'<!DOCTYPE')||str_contains($rels,'<!DOCTYPE'))throw new \InvalidArgumentException('Invalid workbook manifest.');
            $xml=simplexml_load_string($book,\SimpleXMLElement::class,LIBXML_NONET);$relationships=simplexml_load_string($rels,\SimpleXMLElement::class,LIBXML_NONET);
            if($xml===false||$relationships===false)throw new \InvalidArgumentException('Invalid workbook manifest.');
            $rid=null;
            foreach($xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheets->sheet as $sheet)
                if((string)$sheet->attributes()['name']===$name)$rid=(string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target=null;
            foreach($relationships->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel)
                if((string)$rel->attributes()['Id']===$rid && !isset($rel->attributes()['TargetMode']))$target=(string)$rel->attributes()['Target'];
            $part=$target===null?'':(str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target);
            if(!preg_match('~^xl/worksheets/[^/]+\.xml$~',$part))throw new \InvalidArgumentException('Required worksheet relationship missing: '.$name);
            $contents=$zip->getFromName($part);
            if($contents===false||str_contains($contents,'<!DOCTYPE'))throw new \InvalidArgumentException('Invalid worksheet.');
            $reader=new \XMLReader;$reader->XML($contents,null,LIBXML_NONET);$last=0;$row=0;$column='';
            while($reader->read())if($reader->nodeType===\XMLReader::ELEMENT) {
                if($reader->localName==='row')$row=(int)$reader->getAttribute('r');
                elseif($reader->localName==='c')$column=preg_replace('/[0-9]/','',(string)$reader->getAttribute('r'));
                elseif(in_array($reader->localName,['v','t'],true)&&in_array($column,$columns,true)&&$reader->readString()!=='')$last=max($last,$row);
            }
            $reader->close();return $last;
        } finally {$zip->close();}
    }
    private function cell($sheet,string $address): mixed
    {
        $cell=$sheet->getCell($address); $value=$cell->getValue();
        // Cached formula absence is unknown, never a recalculated/presumed zero.
        $value=$cell->isFormula() ? $cell->getOldCalculatedValue() : $value;
        return $value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText ? $value->getPlainText() : $value;
    }
    private function ref(string $file,string $sheet,int $row): array {return ['file'=>$file,'sheet'=>$sheet,'row'=>$row];}
    private function headers($sheet,array $expected): void
    {
        foreach($expected as $address=>$label) if(CompletionData::text($this->cell($sheet,$address))!==$label)
            throw new \InvalidArgumentException('Source column contract differs: '.$sheet->getTitle().'!'.$address);
    }
    private function dateCell($sheet,string $address): mixed
    {
        $value=$this->cell($sheet,$address);
        return is_int($value)||is_float($value) ? Date::excelToDateTimeObject($value,new \DateTimeZone('UTC'))->format('Y-m-d') : $value;
    }
}
