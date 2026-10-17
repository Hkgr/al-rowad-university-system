<?php

namespace App\Services\StudentCompletion;

/** Bounded, cached-value-only OOXML reader. Original paths and workbook prose are never executed. */
final class ConsolidatedHandoff
{
    public const HEADERS=[
        'SourceRows'=>['record_id','source_id','source_sheet','source_row','record_kind','values_json','formulas_json','row_sha256'],
        'Sources'=>['source_id','original_filename','original_sha256','role','extracted_sheets_json','extracted_rows_or_pages','extraction_scope'],
        'ExtractionControl'=>['source_id','source_sheet','retained_rows','first_source_row','last_source_row','row_hashes_sha256'],
        'ConflictReview'=>['record_id','source_number','exam_office_column_B','student_affairs_column_C','problem_column_D','solution_column_E','precedence'],
        'Decisions'=>['decision_id','scope','canonical_number','field','approved_value','authority','approval_local_date','basis','limits_and_provenance'],
        'ExamOwners'=>['record_id','source_id','source_sheet','source_row','original_number','original_name','original_course_code','original_course_name','canonical_number','decision_id','approval','scope_note'],
        'Programs'=>['program_key','college_or_institute','approved_program_label','source_program_label','scope','approved_total_credits','extracted_guidance_rows','source_row_credit_sum','remaining_validation'],
        'Curriculum'=>['record_id','program_key','plan_term','source_code','course_name','credits','prerequisite_source','theoretical_hours','practical_hours','row_kind','authority','notes'],
        'CourseMappings'=>['program_key','original_code','source_subject','credits','decision_status','proposed_system_code','required_handling','decision_id'],
    ];
    private array $parts=[];
    private array $strings=[];
    private string $path;

    public function open(string $path): void
    {
        $this->path=str_replace('\\','/',realpath($path)?:$path);$this->parts=[];$this->strings=[];
        $zip=new \ZipArchive;if($zip->open($path)!==true)$this->bad('Invalid handoff archive.');
        try {
            $book=$this->xml($zip->getFromName('xl/workbook.xml'));$rels=$this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));$targets=[];
            foreach($rels->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
                $a=$rel->attributes();if(isset($a['TargetMode']))continue;$target=(string)$a['Target'];
                $target=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;
                if(preg_match('~^xl/worksheets/[^/]+\.xml$~',$target))$targets[(string)$a['Id']]=$target;
            }
            foreach($book->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheets->sheet as $sheet) {
                $id=(string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if(isset($targets[$id]))$this->parts[(string)$sheet->attributes()['name']]=$targets[$id];
            }
            foreach(array_keys(self::HEADERS) as $name)if(!isset($this->parts[$name]))$this->bad('Required handoff table missing: '.$name);
            if($zip->locateName('xl/sharedStrings.xml')!==false) {
                $r=$this->reader('xl/sharedStrings.xml');
                try {while($r->read())if($r->nodeType===\XMLReader::ELEMENT&&$r->localName==='si')$this->strings[]=$this->text($this->xml($r->readOuterXml()));}
                finally {$r->close();}
            }
        } finally {$zip->close();}
        $contract=false;
        foreach($this->rows('Start') as $row)foreach($row['cells'] as $v)if(is_string($v)&&str_contains($v,'alrowad.student-completion.handoff.v1'))$contract=true;
        if(!$contract)$this->bad('Unsupported consolidated handoff contract.');
    }

    public function table(string $name): array {return iterator_to_array($this->records($name),false);}

    public function records(string $name): \Generator
    {
        $headers=self::HEADERS[$name]??null;if(!$headers)$this->bad('Unsupported machine table.');$seenHeader=false;
        foreach($this->rows($name) as $r) {
            if($r['row']===1) {
                if(array_values($r['cells'])!==$headers)$this->bad('Handoff headers differ: '.$name);
                $seenHeader=true;continue;
            }
            if(!$seenHeader)$this->bad('Missing header: '.$name);
            if(($r['cells']['A']??null)===null||$r['cells']['A']==='')continue;
            $values=[];foreach($headers as $i=>$key)$values[$key]=$r['cells'][\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1)]??null;
            $values['_row']=$r['row'];yield $values;
        }
        if(!$seenHeader)$this->bad('Empty required table: '.$name);
    }

    private function rows(string $name): \Generator
    {
        if(!isset($this->parts[$name]))$this->bad('Missing worksheet: '.$name);$reader=$this->reader($this->parts[$name]);$last=0;
        try {
            while($reader->read()) {
                if($reader->nodeType===\XMLReader::DOC_TYPE)$this->bad('Document types are not allowed.');
                if($reader->nodeType!==\XMLReader::ELEMENT||$reader->localName!=='row')continue;
                $row=(int)$reader->getAttribute('r');if($row<=$last)$this->bad('Duplicate or unordered worksheet row.');$last=$row;
                $outer=$reader->readOuterXml();if(strlen($outer)>2000000)$this->bad('Handoff row exceeds bounded reader capacity.');
                $xml=$this->xml($outer);$cells=[];
                foreach($xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
                    $a=$cell->attributes();$child=$cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $address=(string)$a['r'];if(!preg_match('/^([A-Z]{1,3})'.$row.'$/',$address,$m)||array_key_exists($m[1],$cells))$this->bad('Invalid/duplicate cell address.');
                    $type=(string)$a['t'];$value=null;
                    if($type==='inlineStr')$value=$this->text($child->is);
                    elseif(isset($child->v)) {
                        $raw=(string)$child->v;
                        $value=match($type){'s'=>$this->strings[(int)$raw]??throw new \InvalidArgumentException('Missing shared string.'),'b'=>$raw==='1','str','e'=>$raw,
                            default=>$raw===''?null:(preg_match('/^-?\d+$/',$raw)?(int)$raw:(is_numeric($raw)?(float)$raw:$raw))};
                    }
                    // A formula without a cached value remains NULL; never evaluate it.
                    $cells[$m[1]]=$value;
                }
                yield ['row'=>$row,'cells'=>$cells];
            }
        } finally {$reader->close();}
    }
    private function reader(string $part): \XMLReader
    {
        $r=new \XMLReader;if(!$r->open('zip://'.$this->path.'#'.$part,null,LIBXML_NONET|LIBXML_COMPACT))$this->bad('Cannot stream handoff part.');
        $r->setParserProperty(\XMLReader::LOADDTD,false);$r->setParserProperty(\XMLReader::SUBST_ENTITIES,false);return $r;
    }
    private function xml(string|false $value): \SimpleXMLElement
    {
        if($value===false||str_contains($value,'<!DOCTYPE')||str_contains($value,'<!ENTITY'))$this->bad('Invalid XML part.');
        $xml=simplexml_load_string($value,\SimpleXMLElement::class,LIBXML_NONET|LIBXML_COMPACT);if($xml===false)$this->bad('Invalid XML.');return $xml;
    }
    private function text(\SimpleXMLElement $node): string
    {
        $s='';foreach($node->xpath('.//*[local-name()="t"]') as $t)$s.=(string)$t;return $s;
    }
    /** The documented Python-compatible encoding preserves {} vs [], 0, false and 1.0. */
    public static function sourceHash(string $id,string $values,string $formulas): string
    {
        $v=json_decode($values,false,flags:JSON_THROW_ON_ERROR);$f=json_decode($formulas,false,flags:JSON_THROW_ON_ERROR);
        if(!$v instanceof \stdClass||!$f instanceof \stdClass)throw new \InvalidArgumentException('Source cells/formulas must be JSON objects.');
        $sort=function($v)use(&$sort){if($v instanceof \stdClass){$p=get_object_vars($v);ksort($p,SORT_STRING);foreach($p as &$item)$item=$sort($item);return (object)$p;}if(is_array($v))return array_map($sort,$v);return $v;};
        return hash('sha256',json_encode($sort((object)['id'=>$id,'values'=>$v,'formulas'=>$f]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }
    private function bad(string $message): never {throw new \InvalidArgumentException($message);}
}
