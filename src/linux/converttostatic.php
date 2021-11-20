#!/usr/bin/env php
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
    $blacklist = [
        // todo WTF WHY IDK
        // for unknown reasons, these libs cause crashes and will not be included........
        // libpthread.so.0
        // $ ls
        // *** stack smashing detected ***: <unknown> terminated
        // Aborted
        'libpthread.so.',
        // ./php8.0: /lib/x86_64-linux-gnu/libc.so.6: version `GLIBC_2.28' not found (required by /home/blackdforum/.converttostatic_garbage/7a290334038a3f03f5c7/libxml2.so.2)
        'libxml2.so.',
        // ls: relocation error: /lib/x86_64-linux-gnu/libpthread.so.0: symbol __libc_vfork version GLIBC_PRIVATE not defined in file libc.so.6 with link time reference
        'libc.so.',
        // git --version
        // git: relocation error: /home/blackdforum/.converttostatic_garbage/fe4d434b614951c20ac5/git: symbol clock_gettime version GLIBC_2.2.5 not defined in file librt.so.1 with link time reference
        'librt.so.',
        // /home/blackdforum/.converttostatic_garbage/2b560dddac84acd7bdec/curl: /lib/x86_64-linux-gnu/libc.so.6: version `GLIBC_2.28' not found (required by /home/blackdforum/.converttostatic_garbage/2b560dddac84acd7bdec/libldap_r-2.4.so.2)
        'libldap_r-2.4.so',
        // /home/blackdforum/.converttostatic_garbage/bece8e32e2e780b00f2a/curl: /lib/x86_64-linux-gnu/libc.so.6: version `GLIBC_2.28' not found (required by /home/blackdforum/.converttostatic_garbage/bece8e32e2e780b00f2a/liblber-2.4.so.2)
        'liblber-2.4.so',
        // /home/blackdforum/.converttostatic_garbage/8281da891d378f617c77/curl: /lib/x86_64-linux-gnu/libc.so.6: version `GLIBC_2.28' not found (required by /home/blackdforum/.converttostatic_garbage/8281da891d378f617c77/libsqlite3.so.0)
        'libsqlite3.so'
    ];
    foreach ($deps as $dep => $_) {
        $basename = basename($dep);
        foreach ($blacklist as $blacklisted) {
            if (0 === strpos($basename, $blacklisted)) {
                echo "Warning: will not bundle dependency \"{$basename}\" (known-to-cause-trouble when bundled..)\n";
                unset($deps[$dep]);
                continue 2;
            }
        }
    }
    unset($_); // linter..
    return array_keys($deps);
}

function build_static(string $file)
{
    $dependencies = get_dependencies($file);
    $files = $dependencies;
    $files[] = $file;
    $tar_code = cpptar($files);
    $cpp = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'extractor_template.cpp');
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
    $gpp_return_var = null;
    passthru($cmd, $gpp_return_var);
    if ($gpp_return_var !== 0) {
        die($gpp_return_var);
    }
    $use_upx = false;
    if ($use_upx) {
        $have_upx = $_ = null;
        exec("upx --version 2>&1", $_, $have_upx);
        $have_upx = ($have_upx === 0);

        if (! $have_upx) {
            echo "upx not detected, skipping..\n";
        } else {
            $cmd = implode(" ", array(
                "upx",
                "-9",
                "-v",
                "-o " . escapeshellarg(basename($file) . ".upx9"),
                escapeshellarg(basename($file))
            ));
            echo "cmd:\n{$cmd}\n";
            $upx_return_var = null;
            passthru($cmd, $upx_return_var);
        }
        unset($have_upx, $_);
    }
    die();
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

