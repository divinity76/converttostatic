#include <iostream>
#include <vector>
#include <tuple>
#include <filesystem>
#include <string>
#include <sstream>
#include <iomanip>

#include <cstring>
#include <random>
#include <cstdlib>
#include <fstream>
#include <sys/stat.h>

namespace php
{
    std::string random_bytes(std::size_t size)
    {
        std::random_device rd;
        decltype(rd()) inner_buf;
        // optimizeme: figure out how to construct a string of uninitialized bytes,
        // the zero-initialization is just a waste of cpu
        // (think of it like this: we're using calloc() when we just need malloc())
        std::string ret(size, 0);
        char *buf = (char *)ret.data();
        while (size >= sizeof(inner_buf))
        {
            size -= sizeof(inner_buf);
            inner_buf = rd();
            std::memcpy(buf, &inner_buf, sizeof(inner_buf));
            buf += sizeof(inner_buf);
        }
        if (size > 0)
        {
            inner_buf = rd();
            std::memcpy(buf, &inner_buf, size);
        }
        return ret;
    }

    std::string bin2hex(const std::string &$str)
    {
        // from https://stackoverflow.com/a/18906469/1067003
        std::string ossbuf;
        ossbuf.reserve($str.size() * 2);
        std::ostringstream out(std::move(ossbuf));
        out << std::hex << std::setfill('0');
        for (char c : $str)
        {
            out << std::setw(2) << uint_fast16_t(((unsigned char)c));
        }
        return out.str();
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

}
/*FILE_INJECTION_POINT*/

#ifndef _FILES_INJECTED
const std::vector<std::tuple<std::string, std::string>> files = {
    {std::string("libselinux.so.1", 15), std::string("sample", 6)},
    {std::string("libpcre2-8.so.0", 15), std::string("sample", 6)},
    {std::string("libdl.so.2", 10), std::string("sample", 6)},
    {std::string("ld-linux-x86-64.so.2", 20), std::string("sample", 6)},
    {std::string("libpthread.so.0", 15), std::string("sample", 6)},
    {std::string("ls", 2), std::string("sample", 6)}};
#endif // _FILES_INJECTED

std::string global_temp_dir;
bool global_converttostatic_debugging = false;

void cleanup()
{
    if (global_temp_dir.empty())
    {
        return;
    }
    if (!global_converttostatic_debugging)
    {
        std::filesystem::remove_all(global_temp_dir);
    }
    global_temp_dir.clear();
}

int main(int argc, char *argv[])
{
    constexpr decltype(std::filesystem::path::preferred_separator) separator = std::filesystem::path::preferred_separator;

    // the proper temp_dir may be mounted with noexec...
    //  global_temp_dir = std::filesystem::temp_directory_path().string() + separator;
    global_temp_dir = std::string(getenv("HOME")) + separator + ".converttostatic_garbage" + separator;

    global_temp_dir += php::bin2hex(php::random_bytes(10));
    // sample: /tmp/f8df41a39511e4f45bed/
    // sample: /home/hans/.converttostatic_garbage/61f10c6cf3f5cb9ffc72/
    global_temp_dir += separator;
    std::atexit(cleanup);
    std::at_quick_exit(cleanup);

    // std::cout << "global_temp_dir: " << global_temp_dir << std::endl;
    const auto original_dir = std::filesystem::current_path();
    std::filesystem::create_directories(global_temp_dir);
    std::filesystem::current_path(global_temp_dir);
    std::string last_filename;
    for (auto t : files)
    {
        auto [filename, content] = t;
        std::ofstream file(filename, std::ofstream::out | std::ofstream::binary | std::ofstream::trunc);
        file.exceptions(std::ofstream::failbit | std::ofstream::badbit);
        file.write(content.data(), std::streamsize(content.size()));
        file.close();
        chmod(filename.c_str(), 0755);
        // std::cout << filename << std::endl;
        last_filename = filename;
    }
    std::filesystem::current_path(original_dir);
    std::string cmd = "LD_LIBRARY_PATH=" + php::escapeshellarg(global_temp_dir) + " ";
    cmd += global_temp_dir + last_filename + " ";
    for (int i = 1; i < argc; ++i)
    {
        std::string arg = argv[i];
        if (arg == "--debug-converttostatic-lolxd")
        {
            global_converttostatic_debugging = true;
            continue;
        }
        cmd += php::escapeshellarg(argv[i]) + " ";
    }
    cmd = cmd.substr(0, cmd.size() - 1); // remove last space
    if (global_converttostatic_debugging)
    {
        std::cout << "cmd: " << cmd << std::endl;
    }
    return system(cmd.c_str());
}