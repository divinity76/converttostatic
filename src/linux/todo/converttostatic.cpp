#include <iostream>
#include <vector>
#include <string>
#include <stdio.h>
#include <unistd.h>

#include <string>
#include <vector>
#include <limits>
#include <stdexcept>
#include <algorithm>
#if __cplusplus >= 201703L
#include <filesystem>
#else
#include <fstream>
#endif
#include <cstring>

namespace php
{
    std::string rtrim(std::string $str, const std::string &$character_mask = std::string("\x20\x09\x0A\x0D\x00\x0B", 6))
    {
        // optimizeme: can this be optimized to a single erase() call? probably.
        while ($str.size() > 0 && $character_mask.find_first_of($str.back()) != std::string::npos)
        {
            $str.pop_back();
        }
        return $str;
    }

    std::string ltrim(std::string $str, const std::string &$character_mask = std::string("\x20\x09\x0A\x0D\x00\x0B", 6))
    {
        // optimizeme: can this be optimized to a single erase() call? probably.
        while ($str.size() > 0 && $character_mask.find_first_of($str.front()) != std::string::npos)
        {
            $str.erase(0, 1);
        }
        return $str;
    }

    std::string trim(std::string $str, const std::string &$character_mask = std::string("\x20\x09\x0A\x0D\x00\x0B", 6))
    {
        return rtrim(ltrim($str, $character_mask), $character_mask);
    }

    std::vector<std::string> explode(const std::string &$delimiter, const std::string &$string, const size_t $limit = std::numeric_limits<size_t>::max())
    {
        if ($delimiter.empty())
        {
            throw std::invalid_argument("delimiter cannot be empty!");
        }
        std::vector<std::string> ret;
        if ($limit <= 0)
        {
            return ret;
        }
        size_t pos = 0;
        size_t next_pos = $string.find($delimiter);
        if (next_pos == std::string::npos || $limit == 1)
        {
            ret.push_back($string);
            return ret;
        }
        for (;;)
        {
            ret.push_back($string.substr(pos, next_pos - pos));
            pos = next_pos + $delimiter.size();
            if (ret.size() >= ($limit - 1) || std::string::npos == (next_pos = $string.find($delimiter, pos)))
            {
                ret.push_back($string.substr(pos));
                return ret;
            }
        }
    }
    std::string escapeshellarg(const std::string &$arg)
    {
        std::string ret = "'";
        ret.reserve($arg.length() + 20); // ¯\_(ツ)_/¯
        for (size_t i = 0; i < $arg.length(); ++i)
        {
            if ($arg[i] == '\00')
            {
                throw std::runtime_error("argument contains null bytes, impossible to escape null bytes!");
            }
            else if ($arg[i] == '\'')
            {
                ret += "'\\''";
            }
            else
            {
                ret += $arg[i];
            }
        }
        ret += "'";
        return ret;
    }
    std::string shell_exec(const std::string &cmd, int &return_code)
    {
        // basic idea: duplicate stdout, close stdout, tmpfile() a new stdout with id 1, do your system() thing, then restore stdout...
        // todo: rewrite to use
        constexpr int STDOUT_MAGIC_NUMBER = 1;
        const int stdout_copy = dup(STDOUT_MAGIC_NUMBER);
        if (stdout_copy == -1)
        {
            throw std::runtime_error("dup failed to copy stdout!");
        }
        if (-1 == close(STDOUT_MAGIC_NUMBER))
        {
            close(stdout_copy);
            throw std::runtime_error("close failed to close original stdout!");
        }
        FILE *tmpfile_file = tmpfile();
        if (tmpfile_file == nullptr)
        {
            dup(stdout_copy); //pray it worked and got id 1
            close(stdout_copy);
            throw std::runtime_error("tmpfile failed to create a new stdout!");
        }
        const int tmpfile_fileno = fileno(tmpfile_file);
        if (tmpfile_fileno != STDOUT_MAGIC_NUMBER)
        {
            // to be clear, this *shouldn't happen*
            dup(stdout_copy);   // restore stdout... and pray it got id 1.. i find that unlikely
            close(stdout_copy); //pray it worked..
            fclose(tmpfile_file);
            throw std::runtime_error("tmpfile did not get id 1! it got id " + std::to_string(tmpfile_fileno));
        }
        return_code = system(cmd.c_str());
        const size_t ret_size = size_t(ftell(tmpfile_file));
        rewind(tmpfile_file);
        std::string ret((ret_size), '\00');
        size_t remaining = (ret_size);
        while (remaining > 0)
        {
            const size_t read_size = fread(&ret[ret_size - remaining], 1, remaining, tmpfile_file);
            remaining -= read_size;
            if (read_size == 0)
            {
                throw std::runtime_error("remaining was non-zero but cannot read more! wtf! im so tired of writing error checking code");
            }
        }
        fclose(tmpfile_file);
        dup(stdout_copy); // restore stdout... and pray it got id 1..
        close(stdout_copy);
        return ret;
    }
    std::string shell_exec(const std::string &cmd)
    {
        int dummy_return_code;
        return shell_exec(cmd, dummy_return_code);
    }

    bool file_exists(const std::string &$filename)
    {
#if __cplusplus >= 201703L
        return std::filesystem::exists($filename);
#else
        // this is probably bugged, as the file has to "exist AND be readable",
        std::ifstream f($filename.c_str());
        return f.good();
#endif
    }

} // </namespace php>

std::vector<std::string> get_dependencies(std::string filename)
{
    std::vector<std::string> dependencies;
    auto add_dependency = [&dependencies](std::string dependency) -> void
    {
        if (std::find(dependencies.begin(), dependencies.end(), dependency) == dependencies.end())
        {
            dependencies.push_back(dependency);
        }
    };
    std::string cmd = "ldd -v " + php::escapeshellarg(filename);
    std::string raw = php::shell_exec(cmd);
    std::vector<std::string> lines = php::explode("\n", raw);
    for (size_t lineno = 0; lineno < lines.size(); ++lineno)
    {
        std::string line = php::trim(lines[lineno]);
        if (lineno == 0 && line.substr(0, std::string("linux-vdso.so").size()) == "linux-vdso.so")
        {
            // yeah this file doesn't actually exist: https://stackoverflow.com/a/58657078/1067003
            // ignore
            continue;
        }
        if (line.empty() || line == "Version information:")
        {
            continue;
        }
        if (line[0] == '/')
        {
            // should look something like
            // /lib64/ld-linux-x86-64.so.2 (0x00007ffff7fcf000)
            // /lib/x86_64-linux-gnu/libncursesw.so.6:
            // seems the first whitespace or the first : is the cutoff point..
            std::string dep = line.substr(0, strcspn(line.c_str(), " \t:"));
            if (!php::file_exists(dep))
            {
                throw std::runtime_error("dep 404? failed to extract dep? line: " + line);
            }
            add_dependency(dep);
            continue;
        }

        //PHPV

        const std::string needle = " => ";
        auto pos = line.find(needle);

        if (pos != std::string::npos)
        {
            // should look like
            // libncursesw.so.6 => /lib/x86_64-linux-gnu/libncursesw.so.6 (0x00007ffff7f56000)
            // libtinfo.so.6 (NCURSES6_TINFO_5.7.20081102) => /lib/x86_64-linux-gnu/libtinfo.so.6
            std::string dep = line.substr(pos + needle.size());
            dep = dep.substr(0, strcspn(dep.c_str(), " \t"));
            if (!php::file_exists(dep))
            {
                throw std::runtime_error("dep 404? failed to extract dep? " + line);
            }
            add_dependency(dep);
            continue;
        }
        // "should" be unreachable
        std::cout << raw << std::endl;
        throw std::runtime_error("don't understand this line from ldd: " + line);
    }
    return dependencies;
}

int main(int argc, char *argv[])
{
    (void)argc;
    (void)argv;
    std::vector<std::string> dependencies = get_dependencies(argv[0]);
    std::cout << "test!";
}