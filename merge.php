<?php
$outputFile = 'full_project.txt';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.'));
$handle = fopen($outputFile, 'w');

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== 'merge.php') {
        fwrite($handle, "\n\n--- SOURCE: " . $file->getPathname() . " ---\n\n");
        fwrite($handle, file_get_contents($file->getPathname()));
    }
}
fclose($handle);
echo "Готово! Все файлы собраны в $outputFile";
