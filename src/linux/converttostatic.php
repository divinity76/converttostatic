<?php
declare(strict_types = 1);

function cpp_string(string $str): string
{
    return "std::string(" . cpp_quote($str) . "," . strlen($str) . ")";
}

function cpp_quote(string $str): string
{
    $ret = "";
    $translations = [
        "\r" => "\\r",
        "\n" => "\\n",
        "\t" => "\\t",
        "\\" => "\\\\",
        "'" => "\\'",
        "\"" => "\\\"",
        "?" => "\\?"
    ];
    $whitelist = [
        '(',
        ')',
        '[',
        ']',
        '{',
        '}',
        ',',
        '.',
        '=',
        '=',
        '-',
        '_',
        ":",
        ";",
        ",",
        " ",
        "\$",
        "+",
        "-",
        "/",
        "*",
        "!",
        "|",
        "@",
        "<",
        ">"
        // probably lots more should be on this list.
    ];
    for ($i = 0, $imax = strlen($str); $i < $imax; ++ $i) {
        $chr = $str[$i];
        if (ctype_alnum($chr) || in_array($chr, $whitelist, true)) {
            $ret .= $chr;
        } elseif (isset($translations[$chr])) {
            $ret .= $translations[$chr];
        } else {
            // var_dump($chr);die();
            $ret .= "\\x" . strtoupper(bin2hex($chr)) . '""';
        }
    }
    return '"' . $ret . '"';
}

function cpptar(array $files): string
{
    $ret = "";
    foreach ($files as $filepath) {
        echo "cpptaring {$filepath}..";
        $time = microtime(true);
        $ret .= "{" . cpp_string(basename($filepath)) . "," . cpp_string(file_get_contents($filepath)) . "},\n";
        echo ". done: " . number_format(microtime(true) - $time, 2) . "s\n";
    }
    if (! empty($ret)) {
        $ret = substr($ret, 0, - strlen(",\n")) . "\n";
    }
    $ret = "const std::vector<std::tuple<std::string,std::string>> files = {\n" . $ret . "\n};\n";
    return $ret;
}

function get_dependencies(string $file): array
{
    $file = realpath($file);
    $cmd = "ldd -v " . escapeshellarg($file);
    $raw = shell_exec($cmd);
    $lines = explode("\n", $raw);
    $deps = array();
    foreach ($lines as $lineno => $line) {
        $line = trim($line);
        if ($lineno === 0 && preg_match('/^linux\-vdso\.so/', $line)) {
            // yeah this file doesn't actually exist: https://stackoverflow.com/a/58657078/1067003
            // ignore
            continue;
        }
        if (strlen($line) < 1 || $line === 'Version information:') {
            continue;
        }
        if ($line[0] === '/') {
            // should look something like
            // /lib64/ld-linux-x86-64.so.2 (0x00007ffff7fcf000)
            // /lib/x86_64-linux-gnu/libncursesw.so.6:
            // seems the first whitespace or the first : is the cutoff point..
            $dep = substr($line, 0, strcspn($line, ": \t"));
            if (! file_exists($dep)) {
                throw new \LogicException("dep 404? failed to extract dep? {$line}");
            }
            $deps[$dep] = null;
            continue;
        }
        $needle = " => ";
        $pos = strpos($line, $needle);
        if ($pos !== false) {
            // should look like
            // libncursesw.so.6 => /lib/x86_64-linux-gnu/libncursesw.so.6 (0x00007ffff7f56000)
            // libtinfo.so.6 (NCURSES6_TINFO_5.7.20081102) => /lib/x86_64-linux-gnu/libtinfo.so.6
            $dep = substr($line, $pos + strlen($needle));
            $dep = substr($dep, 0, strcspn($dep, " \t"));
            if (! file_exists($dep)) {
                throw new \LogicException("dep 404? failed to extract dep? {$line}");
            }
            $deps[$dep] = null;
            continue;
        }
        // "should" be unreachable
        throw new LogicException("don't understand this line from ldd: " . $line);
    }
    unset($deps[$file]); // "depends on itself", well duh...
    return array_keys($deps);
}

function build_static(string $file)
{
    $dependencies = get_dependencies($file);
    $files = $dependencies;
    $files[] = $file;
    $tar_code = cpptar($files);
    $cpp = file_get_contents('extractor_template.cpp');
    $replacements = array(
        '/*FILE_INJECTION_POINT*/' => "#define _FILES_INJECTED\n" . $tar_code
    );
    // var_dump($replacements);die();
    $cpp = strtr($cpp, $replacements);
    $cppname = basename($file) . ".cpp";
    file_put_contents($cppname, $cpp, LOCK_EX);
    $cmd = implode(" ", array(
        "g++",
        "-std=c++17",
        "-Wall",
        "-Wextra",
        "-Wpedantic",
        "-Werror",
        "-static",
        // increases compliation time, makes file smaller:
        "-s -Os",
        "-o " . escapeshellarg(basename($file)),
        escapeshellarg($cppname)
    ));
    echo "cmd:\n{$cmd}\n";
    $return_var = null;
    passthru($cmd, $return_var);
    die($return_var);
}

/** @var string[] $argv */
$file = $argv[1] ?? null;
if (empty($file)) {
    die("usage: '{$argv[0]}' '/path/to/bin'\n");
}
$file = realpath($file);
if (! file_exists($file)) {
    die("cannot find that file!\n");
}
build_static($file);

