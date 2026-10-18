<?php
namespace App\Services\HistoricalExams;

/** Bounded MS-OVBA compressed-source decoding, never execution. Original binary hash retained separately. */
final class ExamVbaEvidence
{
    public function extract(string $binary): array
    {
        $file=tmpfile();if(!$file)throw new \RuntimeException('Private VBA temporary stream unavailable.');$ole=new \PhpOffice\PhpSpreadsheet\Shared\OLE;
        try {
            fwrite($file,$binary);fflush($file);$path=stream_get_meta_data($file)['uri'];$ole->read($path);$reader=new \PhpOffice\PhpSpreadsheet\Shared\OLERead;$reader->read($path);$streams=[];$encoding='Windows-1256';$encodingSource='fallback_display_only_original_bytes_retained';
            for($n=0;$n<$ole->ppsTotal();$n++)if($ole->isFile($n)) {
                // Directory indices are CFB entry indices in both public readers. OLERead handles long mini-FAT chains.
                $name=$ole->_list[$n]->Name;$streams[$name]=substr($reader->getStream($n)??'',0,$ole->getDataLength($n));
            }
            if(isset($streams['dir'])) {
                $dir=$this->decode($streams['dir']);
                if(preg_match('/\x03\x00\x02\x00\x00\x00(.{2})/s',$dir,$cp)){$encoding='CP'.unpack('v',$cp[1])[1];$encodingSource='PROJECTCODEPAGE';}
            }
            $modules=[];
            foreach($streams as $stream=>$bytes) {
                if(in_array($stream,['dir','PROJECT','PROJECTwm','_VBA_PROJECT'],true)||str_starts_with($stream,'__SRP'))continue;
                $size=strlen($bytes);
                for($i=0;$i<$size-4;$i++)if(ord($bytes[$i])===1&&(ord($bytes[$i+2])&0x70)===0x30) {
                    try {$text=$this->decode(substr($bytes,$i));}catch(\Throwable){continue;}
                    if(!preg_match('/Attribute VB_Name = "([^"]+)"/',$text,$m)||!preg_match('/\b(?:Sub|Function|Property)\b/',$text))continue;
                    $name=iconv($encoding,'UTF-8',$m[1]);$decoded=iconv($encoding,'UTF-8',$text);if($name===false||$decoded===false)throw new \InvalidArgumentException('VBA source codepage cannot be decoded; original workbook remains unchanged.');
                    $modules[$name]=['text'=>$decoded,'source_base64'=>base64_encode($text),'source_sha256'=>hash('sha256',$text),'extraction'=>'OLE_chained_stream_static_compressed_source_not_executed','display_encoding'=>$encoding,'encoding_source'=>$encodingSource];break;
                }
            }ksort($modules);return $modules;
        } finally {if(is_resource($ole->_file_handle))fclose($ole->_file_handle);fclose($file);}
    }
    public function decode(string $s): string
    {
        if(($s[0]??'')!=="\x01")throw new \InvalidArgumentException('Invalid compressed source.');$p=1;$out='';$n=strlen($s);
        while($p+2<=$n) {
            $start=$p;$header=unpack('v',substr($s,$p,2))[1];$p+=2;
            if(($header&0x7000)!==0x3000)break;$end=min($n,$start+($header&0xfff)+3);$chunk='';
            if(!($header&0x8000)){$chunk=substr($s,$p,4096);$p=$end;}
            else while($p<$end&&strlen($chunk)<4096) {
                $flags=ord($s[$p++]);
                for($bit=0;$bit<8&&$p<$end;$bit++) {
                    if(!($flags&(1<<$bit)))$chunk.=$s[$p++];
                    else {
                        if($p+2>$end||$chunk==='')throw new \InvalidArgumentException('Invalid source token.');
                        $token=unpack('v',substr($s,$p,2))[1];$p+=2;$bits=max(4,(int)ceil(log(strlen($chunk),2)));$mask=0xffff>>$bits;
                        $length=($token&$mask)+3;$offset=($token>>(16-$bits))+1;
                        if($offset>strlen($chunk)||strlen($chunk)+$length>4096)throw new \InvalidArgumentException('Invalid source offset.');
                        for($j=0;$j<$length;$j++)$chunk.=$chunk[strlen($chunk)-$offset];
                    }
                }
            }
            $out.=$chunk;if(strlen($out)>2000000)throw new \InvalidArgumentException('Source decode bound exceeded.');$p=$end;
        }return $out;
    }
}
