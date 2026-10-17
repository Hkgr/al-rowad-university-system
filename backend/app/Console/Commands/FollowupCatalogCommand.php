<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CatalogCompletion\FollowupCatalogService;
use App\Services\StudentCompletion\{CompletionFiles,CompletionSources};
use Illuminate\Console\Command;

/** Dispatch the existing catalog commands without replacing the original pinned manifest contract. */
final class FollowupCatalogCommand
{
    public static function selected(?string $path): bool
    {
        if(!$path||!is_file($path))return false;
        $value=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
        return ($value['contract']??null)==='student-completion.sources.v3';
    }
    public static function run(Command $command,string $action): array
    {
        $bundle=app(CompletionSources::class)->load((string)$command->option('manifest'),(string)$command->option('manifest-hash'));
        $actor=User::findOrFail($command->option('actor'));$service=app(FollowupCatalogService::class);
        if($action==='complete') {
            if($command->option('apply')) {
                foreach(['review','review-hash'] as $option)if(!$command->option($option))throw new \InvalidArgumentException('--'.$option.' is required for this follow-up application.');
                $review=json_decode(file_get_contents($command->option('review')),true,flags:JSON_THROW_ON_ERROR);
                $result=$service->apply($actor,$bundle,$review,(string)$command->option('review-hash'),(string)$command->option('operation-id'),(string)$command->option('expected-revision'),(bool)$command->option('resume'));
            } else $result=$service->preview($actor,$bundle);
        } elseif($action==='status')$result=$service->status($actor,$bundle,(string)$command->argument('operation-id'));
        else $result=$service->verify($actor,$bundle);
        if($command->option('output')) {
            CompletionFiles::write($command->option('output'),$result);
            if(isset($result['review']))CompletionFiles::write($command->option('output').'.review.json',$result['review']);
            return ['output'=>$command->option('output'),'status'=>$result['status'],'review_hash'=>$result['review_hash']??null];
        }
        return $result;
    }
}
