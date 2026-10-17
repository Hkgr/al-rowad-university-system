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
        $value=json_decode($log->description,true,flags:JSON_THROW_ON_ERROR);
        if (!isset($value['chunks'])) return $value;
        $ref=$value['chunks']; $parts=UserActivityLog::where('action_code','student_completion.chunk')->where('description->receipt_id',$ref['id'])->orderBy('activity_log_id')->get();
        if ($parts->count()!==$ref['count']) throw new CompletionException('student_completion_receipt_invalid','Receipt chunks missing.');
        $text='';
        foreach ($parts as $i=>$part) {
            $p=json_decode($part->description,true,flags:JSON_THROW_ON_ERROR);
            if ($p['index']!==$i) throw new CompletionException('student_completion_receipt_invalid','Receipt chunk order differs.');
            $text.=$p['text'];
        }
        $json=base64_decode($text,true);
        if ($json===false || !hash_equals($ref['sha256'],hash('sha256',$json))) throw new CompletionException('student_completion_receipt_invalid','Receipt content differs.');
        return json_decode($json,true,flags:JSON_THROW_ON_ERROR);
    }

    public function put(User $actor,string $stage,string $id,array $value): void
    {
        $data=['operation_id'=>$id,'actor_id'=>(int)$actor->getKey(),'value'=>$value];$json=CompletionData::json($data);
        if (strlen($json)>48000) {
            $receipt=(string)\Illuminate\Support\Str::uuid();$chunks=str_split(base64_encode($json),32000);
            foreach ($chunks as $index=>$text) $this->log($actor,'chunk',['receipt_id'=>$receipt,'index'=>$index,'text'=>$text]);
            $data=['operation_id'=>$id,'actor_id'=>(int)$actor->getKey(),'chunks'=>['id'=>$receipt,'count'=>count($chunks),'sha256'=>hash('sha256',$json)]];
        }
        $this->log($actor,$stage,$data);
    }
    private function log(User $actor,string $stage,array $data): void
    {
        UserActivityLog::create(['user_id'=>$actor->getKey(),'module_code'=>'students','action_code'=>'student_completion.'.$stage,'description'=>CompletionData::json($data)]);
    }
}
