# converttostatic

convert dynamic executables to static, kind-of.
(actually it just kind of makes a self-extracting tar of your executable with all dependencies and
extract it at runtime and invoke it with LD_LIBRARY_PATH)

# usage

lets say you want a static git..

```
hans@xDevAd:~/projects/converttostatic$ ls
LICENSE  README.md  src
hans@xDevAd:~/projects/converttostatic$ php src/linux/converttostatic.php $(which git)
Warning: will not bundle dependency "libpthread.so.0" (known-to-cause-trouble when bundled..)
Warning: will not bundle dependency "librt.so.1" (known-to-cause-trouble when bundled..)
Warning: will not bundle dependency "libc.so.6" (known-to-cause-trouble when bundled..)
cpptaring /usr/lib/x86_64-linux-gnu/libpcre2-8.so.0... done: 0.15s
cpptaring /lib/x86_64-linux-gnu/libz.so.1... done: 0.02s
cpptaring /lib64/ld-linux-x86-64.so.2... done: 0.04s
cpptaring /usr/bin/git... done: 0.67s
cmd:
g++ -std=c++17 -Wall -Wextra -Wpedantic -Werror -static -s -Os -o 'git' 'git.cpp'
cmd:
upx -9 -v -o 'git.upx9' 'git'
                       Ultimate Packer for eXecutables
                          Copyright (C) 1996 - 2018
UPX 3.95        Markus Oberhumer, Laszlo Molnar & John Reiser   Aug 26th 2018

        File size         Ratio      Format      Name
   --------------------   ------   -----------   -----------
   6181688 ->   2637528   42.67%   linux/amd64   git.upx9

Packed 1 file.
hans@xDevAd:~/projects/converttostatic$ ls
git  git.cpp  git.upx9  LICENSE  README.md  src
```

now you have a static ./git, and if you happen to have upx nearby, there will also be a ./git.upx9 compressed version

# warning

the conversion is far from perfect, it will not bundle any of these libraries:

```
libc.so.*
libpthread.so.*
librt.so.*
libxml2.so.*
libldap_r-2.4.so.*
liblber-2.4.so.*
libsqlite3.so.*
```

because they are known to cause issues when bundled.. so if they are required, lets just pray the target system has those libs.. also the converted program will no longer be able to accept the specific argument `--debug-converttostatic-lolxd`

also programs depending on getppid() is likely to malfunction

also the program will be unable to run if your homedir is read-only,
or if your homedir is mounted with "noexec", or if your disk is full