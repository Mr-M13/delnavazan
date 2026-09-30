<?php
$root=dirname(__DIR__); foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $f) if($f->isFile()&&$f->getExtension()==='php') { $out=[];$code=0;exec(PHP_BINARY.' -l '.escapeshellarg($f->getPathname()),$out,$code);if($code)exit($code); } echo "Static PHP lint passed\n";
