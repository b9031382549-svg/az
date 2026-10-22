#!/usr/bin/env bash
# The client's copy of the code: one commit per release on top of their `dev`, holding our
# tree minus exclude.txt plus their own files (theirs.txt). Our history never leaves this repo.
#
#   build.sh preview [rev]           what would go (no VPN needed)
#   build.sh commit  [rev] [notes]   fetch their dev (VPN), build the release commit locally
#   build.sh push    <sha>           push that commit to their dev, then tag our rev client-rN
#
# rev defaults to origin/main; notes = a file whose text goes under "Release N".
# Remote `client` = their GitLab (VPN only; the token sits in ~/.git-credentials). They
# forbid force-push on every branch, so a release must sit right on top of what they have:
# if anyone else committed to their dev outside theirs.txt, `commit` stops and lists them.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
remote=client
branch=dev
exclude=scripts/client-export/exclude.txt
owned=scripts/client-export/theirs.txt
pending=$(git rev-parse --git-path client-export-pending)

die() { echo "ERROR: $*" >&2; exit 1; }

# The client's own paths, read from rev $1 (none in revs from before the list).
owned_paths() { git show "$1:$owned" 2>/dev/null | grep -vE '^[[:space:]]*(#|$)' || true; }

# Of the paths on stdin, those that are not the client's own in rev $1.
not_theirs() {
    local list p o ours
    list=$(owned_paths "$1")
    while IFS= read -r p; do
        ours=1
        while IFS= read -r o; do
            if [[ -n $o && ($p == "$o" || $p == "$o"/*) ]]; then ours=0; break; fi
        done <<< "$list"
        [ $ours = 0 ] || echo "$p"
    done
}

# The snapshot tree of $1: its tree minus the exclude list (read from $1 itself, so the
# same rev always gives the same tree), plus the client's own paths as their commit $2
# has them (none without $2). Built in a scratch index — ours is never touched.
snapshot_tree() {
    local rev=$1 theirs=${2:-} dir line missing=0
    dir=$(mktemp -d)
    export GIT_INDEX_FILE=$dir/index
    git read-tree "$rev"
    while IFS= read -r line || [ -n "$line" ]; do
        [[ -z $line || $line == \#* ]] && continue
        if ! git ls-files --error-unmatch -- ":(literal)$line" >/dev/null 2>&1; then
            echo "$exclude: '$line' matches nothing in $rev" >&2
            missing=1
            continue
        fi
        # -f: the scratch index holds $rev, not HEAD, so git's "differs from HEAD" guard
        # would refuse whenever $rev is not the checked-out commit.
        git rm -r --cached -q -f -- ":(literal)$line"
    done < <(git show "$rev:$exclude")
    # The client's paths are theirs alone: $rev must not hold them, and $theirs' copy goes in.
    # (No errexit inside $(…): every failure has to land in $missing.)
    while IFS= read -r line; do
        if git ls-files --error-unmatch -- ":(literal)$line" >/dev/null 2>&1; then
            echo "$owned: '$line' is the client's, yet $rev holds it" >&2
            missing=1
            continue
        fi
        if [ -n "$theirs" ]; then
            git ls-tree -r -z "$theirs" -- ":(literal)$line" | git update-index -z --index-info \
                || { echo "can't take '$line' from $theirs" >&2; missing=1; }
        fi
    done < <(owned_paths "$rev")
    git write-tree
    unset GIT_INDEX_FILE
    rm -rf "$dir"
    [ $missing = 0 ] || die "fix $exclude / $owned first"
}

# Nothing that must not leave: keys, a real .env, our server, local paths.
check_tree() {
    local tree=$1 bad=0 p
    local pats=(
        'sk-or-v1-[A-Za-z0-9]{20}' 'gh[pousr]_[A-Za-z0-9]{30}' 'github_pat_[A-Za-z0-9_]{30}'
        'glpat-[A-Za-z0-9_.-]{20}' 'BEGIN [A-Z ]*PRIVATE KEY' 'AKIA[0-9A-Z]{16}'
        '157\.90\.152\.161' '/home/b/'
    )
    for p in "${pats[@]}"; do
        if git grep -qE -e "$p" "$tree" --; then
            echo "found /$p/:" >&2
            git grep -lE -e "$p" "$tree" -- | sed "s/^$tree:/  /" >&2
            bad=1
        fi
    done
    # A filled-in secret in an env file; the dev placeholders of .env.example are fine.
    local hits
    hits=$(git grep -nE -e '^[A-Z_]*(KEY|PASSWORD|TOKEN|SECRET)=[^[:space:]#]+' "$tree" -- '*.env*' \
        | grep -vE '=(secret|readonly_secret|null|"\$\{[A-Z_]+\}")$' || true)
    if [ -n "$hits" ]; then
        echo "a filled-in secret in an env file:" >&2
        sed -E "s/^$tree://; s/=.*/=…/; s/^/  /" <<< "$hits" >&2 # never echo the value
        bad=1
    fi
    if git ls-tree -r --name-only "$tree" | grep -qE '(^|/)\.env$'; then
        echo "a real .env is in the tree" >&2
        bad=1
    fi
    [ $bad = 0 ] || die "the snapshot carries something that must stay with us"
}

last_tag() { git tag -l 'client-r*' --sort=-v:refname | head -1; }

# Their commit a release tag points at: the tag's message is "client dev: <sha>".
tag_client_sha() { git tag -l --format='%(contents:subject)' "$1" | awk '{print $NF}'; }

size_mb() { git archive "$1" | wc -c | awk '{printf "%.1f MB", $1/1048576}'; }

cmd_preview() {
    local rev tree last
    rev=$(git rev-parse --verify "${1:-origin/main}^{commit}")
    tree=$(snapshot_tree "$rev")
    check_tree "$tree"
    echo "Snapshot of $(git log -1 --format='%h %ad' --date=short "$rev"): $(git ls-tree -r --name-only "$tree" | wc -l) files, $(size_mb "$tree")"
    echo "(our tree: $(git ls-tree -r --name-only "$rev" | wc -l) files, $(size_mb "$rev"))"
    echo
    echo "Goes to them (top level):"
    git ls-tree -r --name-only "$tree" | awk -F/ '{print (NF > 1 ? $1 "/" : $1)}' | sort | uniq -c | awk '{printf "  %4d  %s\n", $1, $2}'
    echo
    echo "Stays with us ($exclude):"
    local line
    while IFS= read -r line || [ -n "$line" ]; do
        [[ -z $line || $line == \#* ]] && continue
        printf "  %4d  %s\n" "$(git ls-tree -r --name-only "$rev" -- "$line" | wc -l)" "$line"
    done < <(git show "$rev:$exclude")
    echo
    echo "Kept from their $branch as they are ($owned):"
    owned_paths "$rev" | sed 's/^/        /'
    last=$(last_tag)
    if [ -n "$last" ]; then
        echo
        echo "Changes since $last:"
        git diff --stat=120 "$(snapshot_tree "$last")" "$tree" | tail -25
    fi
}

cmd_commit() {
    local rev notes theirs expected last tree n name email msg sha moved p
    rev=$(git rev-parse --verify "${1:-origin/main}^{commit}")
    notes=${2:-}
    [ -z "$notes" ] || [ -f "$notes" ] || die "no notes file $notes"
    name=$(git config client-export.name) && email=$(git config client-export.email) \
        || die "set git config client-export.name / client-export.email (the author on their side)"

    git fetch -q origin --tags
    git fetch -q "$remote" "$branch" || die "can't reach $remote — is the VPN on?"
    theirs=$(git rev-parse "$remote/$branch")
    last=$(last_tag)
    if [ -n "$last" ]; then
        expected=$(tag_client_sha "$last")
    elif [ "$(git rev-list --count "$theirs")" = 1 ]; then
        expected=$theirs # only their initial commit: the first release goes right on it
    else
        die "no release of ours yet, but their $branch has $(git rev-list --count "$theirs") commits — look first"
    fi
    if [ "$theirs" != "$expected" ]; then
        echo "Their $branch moved since $last:" >&2
        git log --format='  %h %an %ad %s' --date=short "$expected..$theirs" >&2 || true
        moved=$(git diff --name-only "$expected" "$theirs" | not_theirs "$rev")
        if [ -n "$moved" ]; then
            sed 's/^/  changed: /' <<< "$moved" >&2
            die "bring these changes into our main first, or the release would overwrite them"
        fi
        echo "Only their own files ($owned) changed: they go out as they are." >&2
    fi

    tree=$(snapshot_tree "$rev" "$theirs")
    # Their own files leave exactly as their dev has them.
    while IFS= read -r p; do
        git diff --quiet "$theirs" "$tree" -- ":(literal)$p" || die "the release would change their '$p'"
    done < <(owned_paths "$rev")
    check_tree "$tree"
    [ "$tree" != "$(git rev-parse "$theirs^{tree}")" ] || die "nothing new for them"

    n=$(( $(git tag -l 'client-r*' | wc -l) + 1 ))
    msg=$(mktemp)
    { echo "Release $n"; [ -z "$notes" ] || { echo; cat "$notes"; }; } > "$msg"
    sha=$(GIT_AUTHOR_NAME=$name GIT_AUTHOR_EMAIL=$email GIT_COMMITTER_NAME=$name GIT_COMMITTER_EMAIL=$email \
        git commit-tree "$tree" -p "$theirs" -F "$msg")
    rm -f "$msg"
    echo "$n $sha $rev" > "$pending"

    echo "Built Release $n = $sha (on their $(git rev-parse --short "$theirs"), from our $(git rev-parse --short "$rev"))"
    git show --stat=120 --format='%an <%ae>%n%n%B' "$sha" | tail -30
    echo
    echo "Nothing is pushed. To send it:  $0 push $sha"
}

cmd_push() {
    local want=${1:-} n sha rev
    [ -f "$pending" ] || die "no release built — run '$0 commit' first"
    read -r n sha rev < "$pending"
    [ "$want" = "$sha" ] || die "the built release is $sha"
    git fetch -q "$remote" "$branch" || die "can't reach $remote — is the VPN on?"
    [ "$(git rev-parse "$remote/$branch")" = "$(git rev-parse "$sha^")" ] \
        || die "their $branch moved after the release was built — run '$0 commit' again"

    git push "$remote" "$sha:refs/heads/$branch"
    git fetch -q "$remote" "$branch"
    [ "$(git rev-parse "$remote/$branch")" = "$sha" ] || die "their $branch is not at $sha after the push"

    git tag -a "client-r$n" "$rev" -m "client $branch: $sha"
    git push -q origin "client-r$n"
    rm -f "$pending"
    echo "Their $branch = $sha; our $(git rev-parse --short "$rev") tagged client-r$n (pushed to origin)."
}

case "${1:-}" in
    preview) shift; cmd_preview "$@" ;;
    commit) shift; cmd_commit "$@" ;;
    push) shift; cmd_push "$@" ;;
    *) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
