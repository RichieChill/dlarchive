<?php

if ($argc < 3 || in_array('-h', $argv)) {
    echo "This script downloads files listed in an Archive.org XML file and preserves their directory structure.\n";
    echo "Usage: php {$argv[0]} <target_directory> <xml_url>\n";
    exit(0);
}

$targetDir = rtrim($argv[1], DIRECTORY_SEPARATOR);
$xmlUrl = $argv[2];

function encodeUrlPath($path) {
    // Encode each part of the path separately to handle special characters
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function escapeWindowsPath($path) {
    // Double-quote the path for safe use in Windows command line
    return '"' . str_replace('"', '""', $path) . '"';
}

function fixEntityEncoding($path) {
    // Replace common HTML entities in file names
    return str_replace('&amp;', '&', $path);
}

function downloadFileWithCurl($url, $path) {
    $safePath = escapeWindowsPath($path); // Escape the file path for Windows
    $safeUrl = escapeWindowsPath($url);  // Escape the URL for Windows

    // Ensure the directory exists
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    // Use `curl.exe` to download the file
    $command = "curl.exe -s -L -o $safePath $safeUrl";
    $output = [];
    $returnVar = 0;

    exec($command, $output, $returnVar);

    return $returnVar === 0;
}

function attemptDownload($url, $path, $maxRetries = 3) {
    $attempts = 0;
    while ($attempts < $maxRetries) {
        $attempts++;
        if (downloadFileWithCurl($url, $path)) {
            return true;
        }
    }
    return false;
}

echo "Fetching XML from $xmlUrl...\n";
$xmlContent = @file_get_contents($xmlUrl);

if ($xmlContent === false) {
    echo "Error: Unable to fetch XML file.\n";
    exit(1);
}

// Extract the identifier from the XML URL
preg_match('/\/([^\/]+)_files\.xml$/', $xmlUrl, $identifierMatch);
if (empty($identifierMatch[1])) {
    echo "Error: Unable to extract identifier from XML URL.\n";
    exit(1);
}
$identifier = $identifierMatch[1];
$baseUrl = "https://archive.org/download/$identifier/";

// Match file names within <file> elements
preg_match_all('/<file\s+name="([^"]+)"/i', $xmlContent, $matches);

if (empty($matches[1])) {
    echo "No files found in the XML.\n";
    exit(1);
}

$totalFiles = count($matches[1]);
$successCount = 0;
$failureCount = 0;

$errorLogPath = "$targetDir/_errors.log";
$errorLog = fopen($errorLogPath, 'w');

foreach ($matches[1] as $index => $relativePath) {
    $relativePath = fixEntityEncoding($relativePath); // Fix HTML entities in the file name
    $encodedPath = encodeUrlPath($relativePath);     // Encode the relative path for the URL
    $fileUrl = $baseUrl . $encodedPath;             // Construct the full file URL
    $savePath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

    echo ($index + 1) . " [", date('Y-m-d H:i:s'), "] $relativePath... ";

    if (attemptDownload($fileUrl, $savePath)) {
        echo "\033[32mSUCCESS\033[0m\n";
        $successCount++;
    } else {
        echo "\033[31mFAILED\033[0m\n";
        fwrite($errorLog, "$fileUrl\n");
        $failureCount++;
    }
}

fclose($errorLog);

if ($failureCount === 0) {
    unlink($errorLogPath);
}

echo "\nSummary:\n";
echo "Total files: \033[36m$totalFiles\033[0m\n";
echo "Success: \033[36m$successCount\033[0m\n";
echo "Failed: \033[36m$failureCount\033[0m\n";

exit($failureCount > 0 ? 1 : 0);
?>
