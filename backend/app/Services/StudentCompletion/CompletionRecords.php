<?php

namespace App\Services\StudentCompletion;

/** Private process-lifetime spool. Only an index and one decoded record are resident at a time. */
final class CompletionRecords implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    private $file;
    private array $index=[];
    public function __construct(private bool $list=true)
    {
        $this->file=tmpfile();
        if(!$this->file)throw new \RuntimeException('Cannot create private completion spool.');
        @chmod(stream_get_meta_data($this->file)['uri'],0600);
    }
    public function __destruct() { if(is_resource($this->file))fclose($this->file); }
    public function count(): int { return count($this->index); }
    public function offsetExists(mixed $offset): bool { return array_key_exists($offset,$this->index); }
    public function offsetGet(mixed $offset): mixed
    {
        [$position,$length]=$this->index[$offset]??throw new \OutOfBoundsException('Completion record missing.');
        if(fseek($this->file,$position)!==0)throw new \RuntimeException('Cannot seek completion spool.');
        $text='';while(strlen($text)<$length){$part=fread($this->file,$length-strlen($text));if($part===false||$part==='')throw new \RuntimeException('Cannot read completion spool.');$text.=$part;}
        return json_decode($text,true,512,JSON_THROW_ON_ERROR);
    }
    public function offsetSet(mixed $offset,mixed $value): void
    {
        $text=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(fseek($this->file,0,SEEK_END)!==0)throw new \RuntimeException('Cannot seek completion spool.');
        $position=ftell($this->file);if($position===false)throw new \RuntimeException('Cannot index completion spool.');CompletionJson::writeAll($this->file,$text);
        if($offset===null)$this->index[]=[$position,strlen($text)];else $this->index[$offset]=[$position,strlen($text)];
    }
    public function offsetUnset(mixed $offset): void { throw new \LogicException('Completion records cannot be removed.'); }
    public function getIterator(): \Traversable { foreach($this->index as $key=>$_)yield $key=>$this[$key]; }
    /** Keep existing small service/test consumers array-compatible, without materializing production populations. */
    public function compact(): array|self
    {
        if(count($this)>128)return $this;
        $bytes=0;foreach($this->index as [, $length]){$bytes+=$length;if($bytes>4194304)return $this;}
        return iterator_to_array($this);
    }
    public function tokens(): \Generator
    {
        yield $this->list?'[':'{';$keys=array_keys($this->index);if(!$this->list)sort($keys,SORT_STRING);$first=true;
        foreach($keys as $key){if(!$first)yield ',';$first=false;if(!$this->list){yield json_encode((string)$key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);yield ':';}yield from CompletionData::tokens($this[$key]);}
        yield $this->list?']':'}';
    }
    public function jsonSerialize(): mixed { throw new \LogicException('Use CompletionData/CompletionFiles to stream completion records.'); }
}
