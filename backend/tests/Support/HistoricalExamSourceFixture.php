<?php
namespace Tests\Support;

/** Minimal synthetic OOXML/cached formula fixtures. No real workbook or SQL import. */
final class HistoricalExamSourceFixture
{
    public static function write(string $directory): array
    {
        $inputs=[];
        foreach(['exam1'=>['OutcomeofMater','supplementary'],'exam2'=>['علامات','تكميلي']] as $id=>$names) {
            $path=$directory.'/'.$id.'.xlsm';$z=new \ZipArchive;$z->open($path,\ZipArchive::CREATE|\ZipArchive::EXCL);$sheets='';$rels='';
            foreach($names as $i=>$name) {
                $sheets.='<sheet name="'.$name.'" sheetId="'.($i+1).'" r:id="rId'.($i+1).'"/>';$rels.='<Relationship Id="rId'.($i+1).'" Target="worksheets/sheet'.($i+1).'.xml"/>';
                $cells=$i?['B1'=>'رمز المقرر','F1'=>'الرقم الجامعي','G1'=>'الاسم','J1'=>'الامتحان النهائي']:['E1'=>'رمز المقرر','H1'=>'الرقم الجامعي','I1'=>'الاسم','K1'=>'الامتحان النهائي','R1'=>'العام الدراسي'];
                if(!$i)$cells+=['B2'=>'College','C2'=>'Program','D2'=>'1','E2'=>'C1','F2'=>'Course','G2'=>'3','H2'=>'S1','I2'=>'Student 1','J2'=>'30','K2'=>'50','M2'=>'ignored Excel outcome','N2'=>'4','O2'=>'T','P2'=>'99','R2'=>'2025/2026'];
                $rows=[];foreach($cells as $address=>$value){preg_match('/\d+/',$address,$m);$rows[$m[0]][]='<c r="'.$address.'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';}
                if(!$i)$rows[2][]='<c r="L2"><f>J2+K2</f><v>80</v></c>';
                $xml='';foreach($rows as $row=>$cs)$xml.='<row r="'.$row.'">'.implode('',$cs).'</row>';
                $z->addFromString('xl/worksheets/sheet'.($i+1).'.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
            }
            $z->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
            $z->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');$z->close();$inputs[$id]=['path'=>$path,'sha256'=>hash_file('sha256',$path)];
        }
        $path=$directory.'/synthetic.sql';file_put_contents($path,"DROP DATABASE never_execute_me;\nINSERT INTO `students` (`student_id`,`student_number`,`first_name`,`last_name`,`academic_program_id`) VALUES (1,'S1','Student','1',1);\nINSERT INTO `student_statuses` (`student_status_id`,`status_code`) VALUES (1,'active');\nINSERT INTO `academic_years` (`academic_year_id`,`year_name`) VALUES (1,'2025-2026');\nINSERT INTO `academic_programs` (`academic_program_id`,`program_name`,`department_id`,`program_code`) VALUES (1,'Program',1,'TEST-P');\n");
        $inputs['snapshot']=['path'=>$path,'sha256'=>hash_file('sha256',$path)];return $inputs;
    }
}
