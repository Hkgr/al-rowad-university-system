<?php

namespace App\Services\StudentCompletion;

/** Private artifacts must never land in the checkout or public web tree. */
final class CompletionFiles
{
    public static function write(string $path,array $value): void
    {
        $parent=realpath(dirname($path));
        if ($parent===false) throw new \InvalidArgumentException('Create a private output directory outside the repository first.');
        $root=str_replace('\\','/',realpath(base_path('..')));$target=str_replace('\\','/',$parent).'/'.basename($path);
        if (str_starts_with(strtolower($target).'/',strtolower($root).'/')) throw new \InvalidArgumentException('Identifiable reports cannot be written inside the repository.');
        if (file_exists($path)) throw new \InvalidArgumentException('Refusing to overwrite an existing private artifact: '.$path);
        $handle=fopen($path,'xb');if(!$handle)throw new \RuntimeException('Cannot create private artifact.');
        try {
            foreach(CompletionData::blocks($value) as $part)CompletionJson::writeAll($handle,$part);
            if(fwrite($handle,"\n")!==1)throw new \RuntimeException('Private artifact write failed.');
        }
        finally {fclose($handle);}
        @chmod($path,0600);
    }
}
