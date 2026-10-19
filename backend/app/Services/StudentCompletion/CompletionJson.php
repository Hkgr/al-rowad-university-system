<?php

namespace App\Services\StudentCompletion;

/** Streaming JSON envelope reader; selected large record lists spill to private temporary files. */
final class CompletionJson
{
    private string $buffer='';
    private int $position=0;
    private const MAX_RECORD_BYTES=67108864;
    private function __construct(private $stream,private array $lists) {}
    public static function file(string $path,array $lists=['actions','review.actions','rows']): array
    {
        $stream=fopen($path,'rb');if(!$stream)throw new \InvalidArgumentException('Cannot read completion artifact.');
        try{return self::read($stream,$lists);}finally{fclose($stream);}
    }
    public static function read($stream,array $lists=[]): array
    {
        $reader=new self($stream,$lists);$value=$reader->value('');$reader->whitespace();
        if($reader->peek()!==null||!is_array($value))throw new \UnexpectedValueException('Invalid completion JSON envelope.');
        return $value;
    }
    public static function writeAll($stream,string $text): void
    {
        $offset=0;$length=strlen($text);
        while($offset<$length){$n=fwrite($stream,substr($text,$offset));if($n===false||$n===0)throw new \RuntimeException('Completion stream write failed.');$offset+=$n;}
    }
    private function peek(): ?string
    {
        if($this->position===strlen($this->buffer)){$buffer=fread($this->stream,65536);if($buffer===false)throw new \RuntimeException('Completion stream read failed.');$this->buffer=$buffer;$this->position=0;}
        return $this->buffer[$this->position]??null;
    }
    private function take(): string { $c=$this->peek();if($c===null)throw new \UnexpectedValueException('Truncated completion JSON.');$this->position++;return $c; }
    private function whitespace(): void { while(($c=$this->peek())!==null&&str_contains(" \t\r\n",$c))$this->position++; }
    private function expect(string $c): void { $this->whitespace();if($this->take()!==$c)throw new \UnexpectedValueException('Invalid completion JSON delimiter.'); }
    private function value(string $path,int $depth=0): mixed
    {
        $this->whitespace();$c=$this->peek();
        if(($c==='{'||$c==='[')&&$depth>=511)throw new \UnexpectedValueException('Completion envelope nesting limit exceeded.');
        if($c==='{') {
            $this->take();$value=[];$seen=[];$this->whitespace();if($this->peek()==='}'){$this->take();return [];}
            while(true){$this->whitespace();if($this->peek()!=='"')throw new \UnexpectedValueException('Invalid completion object key.');$key=json_decode($this->raw(),true,512,JSON_THROW_ON_ERROR);
                if(isset($seen[$key]))throw new \UnexpectedValueException('Duplicate completion object key.');$seen[$key]=true;$this->expect(':');
                $value[$key]=$this->value($path===''?$key:$path.'.'.$key,$depth+1);$this->whitespace();$delimiter=$this->take();if($delimiter==='}')return $value;if($delimiter!==',')throw new \UnexpectedValueException('Invalid completion object.');}
        }
        if($c==='[') {
            $this->take();$spill=in_array($path,$this->lists,true);$value=$spill?new CompletionRecords:[];$this->whitespace();
            if($this->peek()===']'){$this->take();return [];}
            while(true){$value[]=$spill?json_decode($this->raw(),true,511-$depth,JSON_THROW_ON_ERROR):$this->value($path.'.*',$depth+1);$this->whitespace();$delimiter=$this->take();
                if($delimiter===']')return $spill?$value->compact():$value;if($delimiter!==',')throw new \UnexpectedValueException('Invalid completion list.');}
        }
        return json_decode($this->raw(),true,512,JSON_THROW_ON_ERROR);
    }
    /** Capture one JSON value, not the complete list/envelope. json_decode validates that bounded value. */
    private function raw(): string
    {
        $this->whitespace();$first=$this->peek();if($first===null)throw new \UnexpectedValueException('Truncated completion JSON.');
        $text='';$depth=0;$quoted=false;$escaped=false;$container=$first==='{'||$first==='[';$string=$first==='"';
        do {
            $c=$this->peek();if($c===null)throw new \UnexpectedValueException('Truncated completion JSON value.');
            if(!$quoted&&!$container&&!$string&&str_contains(" \t\r\n,]}",$c))break;
            // Skip ordinary runs in one operation; delimiters and string escapes are handled below.
            $special=$quoted?'"\\':'{}[]"';
            if(!$escaped&&($container||$string)) {
                $n=strcspn($this->buffer,$special,$this->position);
                if($n>0){$text.=substr($this->buffer,$this->position,$n);$this->position+=$n;if(strlen($text)>self::MAX_RECORD_BYTES)throw new \UnexpectedValueException('Completion record too large.');continue;}
            }
            $this->position++;$text.=$c;
            if($quoted){if($escaped)$escaped=false;elseif($c==='\\')$escaped=true;elseif($c==='"')$quoted=false;}
            elseif($c==='"')$quoted=true;
            elseif($c==='{'||$c==='[')$depth++;
            elseif($c==='}'||$c===']')$depth--;
            if(strlen($text)>self::MAX_RECORD_BYTES)throw new \UnexpectedValueException('Completion record too large.');
            if(($container&&$depth===0)||($string&&!$quoted&&strlen($text)>1))break;
        } while(true);
        return $text;
    }
}
