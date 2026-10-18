#!/bin/sh
# [CN-PATCH 13] 重新生成 docker/expected-patches.txt
#
# 用途：列出仓库里「所有带 CN-PATCH 标记的源码文件」。
# Dockerfile.cn 会逐个确认它们确实被 COPY 进了镜像，漏一个就构建失败。
#
# 什么时候要跑：
#   - 新增了一处补丁之后
#   - 合并上游、解决冲突之后（冲突解决时可能整段覆盖掉标记）
#
# 跑完记得把改动提交，否则下一次构建用的还是旧清单。

set -e
cd "$(dirname "$0")/.."

OUT=docker/expected-patches.txt

{
    echo '# [CN-PATCH 13] 镜像必须覆盖的文件清单。'
    echo '#'
    echo '# 自动生成，请勿手改。Dockerfile.cn 的构建期自检会逐个检查这些文件是否'
    echo '# 真的被 COPY 进镜像，漏一个就构建失败 —— 因为漏覆盖的后果是静默的：'
    echo '# 镜像能构建、容器能启动，只有走那条代码路径的用户会拿到错误数据。'
    echo '#'
    echo '# 重新生成：sh tools/gen-expected-patches.sh'
    echo ''
    # 只列会进镜像的源码。排除 libs/（上游第三方库）、tests/、
    # docker/（构建文件自身不需要进镜像）、以及本脚本自己。
    #
    # 包含 *.css：CN-PATCH 16 的层叠层级修正在 styles.css 里，
    # 最初只扫 php/js，导致这个文件没被加入 COPY 清单也没被自检发现。
    {
        grep -rl 'CN-PATCH' --include='*.php' --include='*.js' --include='*.css' . 2>/dev/null
        # startup.sh 是 shell 脚本，单独带上
        echo './startup.sh'
    } \
        | sed 's|^\./||' \
        | grep -v '^libs/' \
        | grep -v '^tests/' \
        | grep -v '^docker/' \
        | sort -u
} > "$OUT"

echo "已写入 $OUT："
grep -v '^#' "$OUT" | grep -v '^$' | nl
