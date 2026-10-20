<?php
if(PHP_SAPI!=='cli')exit;
$count=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__),FilesystemIterator::SKIP_DOTS)) as $file){
    if($file->getExtension()!=='php')continue;
    token_get_all(file_get_contents($file->getPathname()),TOKEN_PARSE);$count++;
}
echo "PHP ".PHP_VERSION.": $count files parsed successfully.\n";
