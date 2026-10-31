<?php

/** Reproducible source -> standalone namespace-isolated merchant ZIP. */

declare(strict_types=1);

$root = dirname(__DIR__);
$local = $argv[1] ?? null;
if ($local !== null && (!is_dir($local . '/src/Commerce') || !is_file($local . '/composer.json'))) {
    throw new RuntimeException('Supply an SDK source checkout with Commerce support.');
}
$input = $root . '/build/input';
$output = $root . '/build/release';
function run(array $command, string $cwd): void
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Build command failed.');
    }
}
function copyTree(string $source, string $target): void
{
    if (is_dir($source)) {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        foreach (new DirectoryIterator($source) as $entry) {
            if (!$entry->isDot()) {
                copyTree($entry->getPathname(), $target . '/' . $entry->getFilename());
            }
        }
    } else {
        copy($source, $target);
    }
}
function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir() && !$entry->isLink()) {
            removeTree($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}
removeTree($input);
removeTree($output);
mkdir($input, 0755, true);
mkdir($output, 0755, true);
$config = ['name' => 'lunixi/whmcs-release-input', 'require' => ['lunixi/php-sdk' => '0.29.3'], 'config' => ['allow-plugins' => false, 'sort-packages' => true]];
if ($local !== null) {
    $config['repositories'] = [['type' => 'path', 'url' => realpath($local), 'options' => ['symlink' => false]]];
}
file_put_contents($input . '/composer.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
run(['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--classmap-authoritative'], $input);
foreach (['modules', 'LICENSE', 'README.md', 'README.tr.md', 'CHANGELOG.md', 'SECURITY.md', 'whmcs.json', 'module-version.json', 'docs'] as $file) {
    if (file_exists($root . '/' . $file)) { copyTree($root . '/' . $file, $output . '/' . $file); }
}
$sdk = $output . '/modules/gateways/lunixi/sdk';
$source = $input . '/vendor/lunixi/php-sdk';
run([PHP_BINARY, $root . '/tools/vendor/bin/php-scoper', 'add-prefix', 'src', '--output-dir=' . $sdk, '--config=' . $root . '/scoper.inc.php', '--force', '--no-interaction'], $source);
copy($source . '/LICENSE', $sdk . '/LICENSE');
$prefix = 'Lunixi\\WHMCS\\Vendor\\Lunixi\\Sdk\\';
$autoload = '<?php' . "\ndeclare(strict_types=1);\nspl_autoload_register(static function (string \$class): void {\n";
$autoload .= '    $prefix = ' . var_export($prefix, true) . ";\n";
$autoload .= '    if (strncmp($class, $prefix, strlen($prefix)) === 0) {' . "\n";
$autoload .= '        $file = __DIR__ . "/" . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";' . "\n";
$autoload .= '        if (is_file($file)) { require_once $file; }' . "\n    }\n});\n";
file_put_contents($sdk . '/autoload.php', $autoload);
if (!is_file($sdk . '/LunixiClient.php')) {
    throw new RuntimeException('Scoped SDK layout is invalid.');
}
// Deny direct PHP execution through Apache; merchant uses only platform controllers.
file_put_contents($output . '/modules/gateways/lunixi/src/.htaccess', "Require all denied\n");
file_put_contents($sdk . '/.htaccess', "Require all denied\n");
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS)) as $entry) {
    if (!$entry->isFile()) {
        continue;
    }
    $text = file_get_contents($entry->getPathname());
    if (preg_match('~/(?:Users|home)/|git[.][A-Za-z0-9.-]+|(?<![A-Za-z])(?:\\.\\./){2}php/~', $text)) {
        throw new RuntimeException('Internal reference in release artifact.');
    }
}
if (!is_dir($root . '/dist')) {
    mkdir($root . '/dist');
}
$zip = new ZipArchive();
$target = $root . '/dist/lunixi-whmcs-0.1.0.zip';
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create ZIP.');
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS)) as $entry) {
    if ($entry->isFile()) {
        $zip->addFile($entry->getPathname(), substr($entry->getPathname(), strlen($output) + 1));
    }
}
$zip->close();
echo 'Built ' . basename($target) . ' SHA256 ' . hash_file('sha256', $target) . "\n";
