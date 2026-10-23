<?php

namespace App\Services\StudentCompletion;

use App\Models\{User,UserActivityLog};

/** Durable append-only operational receipts in the existing audit facility, including TEXT-safe chunks. */
final class CompletionReceipts
{
    public function find(string $stage,string $id): ?array
    {
        $log=UserActivityLog::where('action_code','student_completion.'.$stage)->where('description->operation_id',$id)->orderByDesc('activity_log_id')->first();
        if (!$log) return null;
        try{$value=json_decode($log->description,true,flags:JSON_THROW_ON_ERROR);}catch(\JsonException){$this->invalid('Receipt manifest JSON invalid.');}
        if (!isset($value['chunks'])) return $this->envelope($value);
        $ref=$value['chunks'];
        if(!is_int($ref['count']??null)||$ref['count']<1||!is_string($ref['id']??null)||!preg_match('/^[a-f0-9]{64}$/D',$ref['sha256']??''))$this->invalid('Receipt chunk manifest invalid.');
        $stream=tmpfile();if(!$stream)throw new \RuntimeException('Cannot create private receipt spool.');
        @chmod(stream_get_meta_data($stream)['uri'],0600);
        try {
            $hash=hash_init('sha256');$i=0;$carry='';$padded=false;
            $parts=UserActivityLog::where('action_code','student_completion.chunk')->where('description->receipt_id',$ref['id'])
                ->select(['activity_log_id','description'])->lazyById(128,'activity_log_id');
            foreach($parts as $part) {
                $p=json_decode($part->description,true,flags:JSON_THROW_ON_ERROR);
                if(($p['index']??null)!==$i||($p['receipt_id']??null)!==$ref['id']||!is_string($p['text']??null))$this->invalid('Receipt chunk order differs.');
                $i++;$text=preg_replace('/[\r\n\t ]/','',$p['text']);if($padded&&$text!=='')$this->invalid('Receipt base64 padding differs.');
                $carry.=$text;$length=intdiv(strlen($carry),4)*4;$segment=substr($carry,0,$length);$carry=substr($carry,$length);
                $decoded=base64_decode($segment,true);if($decoded===false)$this->invalid('Receipt encoding differs.');
                if(str_contains($segment,'=')){$padded=true;if($carry!=='')$this->invalid('Receipt encoding differs.');}
                hash_update($hash,$decoded);CompletionJson::writeAll($stream,$decoded);
            }
            if($i!==$ref['count']||$carry!==''||!hash_equals($ref['sha256'],hash_final($hash)))$this->invalid('Receipt chunks missing or content differs.');
            rewind($stream);
            $data=$this->envelope(CompletionJson::read($stream,in_array($stage,['exam_start','exam_result'],true)?['value.review.actions','value.result.actions']:[]));
            if($data['operation_id']!==($value['operation_id']??null)||$data['actor_id']!==($value['actor_id']??null))$this->invalid('Receipt envelope differs from its manifest.');
            return $data;
        } catch(\JsonException|\UnexpectedValueException $e) {
            $this->invalid('Receipt JSON is invalid or exceeds the bounded-record limit.');
        } finally { fclose($stream); }
    }

    public function put(User $actor,string $stage,string $id,array $value): void
    {
        $data=['operation_id'=>$id,'actor_id'=>(int)$actor->getKey(),'value'=>$value];$stream=tmpfile();
        if(!$stream)throw new \RuntimeException('Cannot create private receipt spool.');@chmod(stream_get_meta_data($stream)['uri'],0600);
        try {
            $hash=hash_init('sha256');foreach(CompletionData::blocks($data) as $part){hash_update($hash,$part);CompletionJson::writeAll($stream,$part);}
            $length=ftell($stream);if($length===false)throw new \RuntimeException('Receipt spool position failed.');$digest=hash_final($hash);rewind($stream);
            if($length<=48000){$this->logJson($actor,$stage,stream_get_contents($stream));return;}
            $receipt=(string)\Illuminate\Support\Str::uuid();$count=0;
            // 24,000 bytes -> 32,000 base64 characters: byte-identical to the legacy chunk format.
            while(!feof($stream)){$part=fread($stream,24000);if($part===false)throw new \RuntimeException('Receipt spool read failed.');if($part==='')break;
                $this->log($actor,'chunk',['receipt_id'=>$receipt,'index'=>$count++,'text'=>base64_encode($part)]);}
            $this->log($actor,$stage,['operation_id'=>$id,'actor_id'=>(int)$actor->getKey(),'chunks'=>['id'=>$receipt,'count'=>$count,'sha256'=>$digest]]);
        } finally { fclose($stream); }
    }
    private function invalid(string $message): never { throw new CompletionException('student_completion_receipt_invalid',$message); }
    private function envelope(mixed $value): array
    {
        if(!is_array($value)||!is_string($value['operation_id']??null)||!is_int($value['actor_id']??null)||!is_array($value['value']??null))$this->invalid('Receipt envelope invalid.');
        return $value;
    }
    private function log(User $actor,string $stage,array $data): void
    {
        $this->logJson($actor,$stage,CompletionData::json($data));
    }
    private function logJson(User $actor,string $stage,string $json): void { UserActivityLog::create(['user_id'=>$actor->getKey(),'module_code'=>'students','action_code'=>'student_completion.'.$stage,'description'=>$json]); }
}
