#!/usr/bin/env python3
"""Restricted Pi archive helper.

Installed as the forced command for the dedicated archive account. It accepts either an
rsync server command (delegated to rrsync rooted at the archive) or a single base64 JSON
operation for verification/commit/delete. No arbitrary shell is available.
"""
import base64
import hashlib
import json
import os
import shlex
import sys
import time

ROOT = os.environ.get("DZN_ARCHIVE_ROOT", "/mnt/mbox/DelnavazanRecordings/archive")
MARKER = os.environ.get("DZN_ARCHIVE_MARKER", "/mnt/mbox/.delnavazan-mount")


def safe(relative: str) -> str:
    if not isinstance(relative, str) or not relative or "\x00" in relative:
        raise ValueError("archive_path_invalid")
    if relative.startswith("/") or relative.startswith("..") or "/../" in relative:
        raise ValueError("archive_path_invalid")
    normalized = os.path.normpath(relative)
    if normalized.startswith("..") or normalized.startswith("/"):
        raise ValueError("archive_path_invalid")
    path = os.path.join(ROOT, normalized)
    real_root = os.path.realpath(ROOT)
    real_parent = os.path.realpath(os.path.dirname(path))
    if not (real_parent == real_root or real_parent.startswith(real_root + os.sep)):
        raise ValueError("archive_path_invalid")
    return path


def digest(path: str) -> tuple[int, str]:
    if os.path.islink(path) or not os.path.isfile(path):
        raise ValueError("archive_file_missing")
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(chunk)
    return os.path.getsize(path), h.hexdigest()


def fsync_file(path: str) -> None:
    if hasattr(os, "O_DIRECTORY"):
        fd = os.open(path, os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)


def fsync_dir(path: str) -> None:
    if hasattr(os, "O_DIRECTORY"):
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)


def receipt(path: str) -> str:
    st = os.stat(path)
    return f"dev={st.st_dev};ino={st.st_ino};size={st.st_size};mtime={int(st.st_mtime)}"


def identity() -> dict:
    if not os.path.isfile(MARKER):
        raise ValueError("archive_mount_absent")
    with open(MARKER, "r", encoding="utf-8") as handle:
        value = handle.readline().strip()
    if not value:
        raise ValueError("archive_identity_unverified")
    st = os.statvfs(ROOT)
    return {"mounted": True, "identity": value, "free_bytes": st.f_bavail * st.f_frsize}


def perform(payload: dict) -> dict:
    op = payload.get("op")
    if op == "identity":
        return identity()
    if op == "mkdir":
        path = safe(payload["path"])
        os.makedirs(path, mode=0o750, exist_ok=True)
        os.chmod(path, 0o750)
        return {"ok": True}
    if op == "probe":
        path = safe(payload["path"])
        if not os.path.exists(path):
            return {"exists": False}
        size, sha = digest(path)
        return {"exists": True, "bytes": size, "sha256": sha}
    if op == "commit":
        temporary = safe(payload["temporary"])
        final = safe(payload["final"])
        expected_sha = str(payload["sha256"]).lower()
        expected_bytes = int(payload["bytes"])
        size, sha = digest(temporary)
        if size != expected_bytes or sha != expected_sha:
            raise ValueError("archive_checksum_mismatch")
        if os.path.exists(final):
            fsize, fsha = digest(final)
            if fsize != expected_bytes or fsha != expected_sha:
                raise ValueError("archive_destination_conflict")
        else:
            os.makedirs(os.path.dirname(final), mode=0o750, exist_ok=True)
            os.replace(temporary, final)
        os.chmod(final, 0o640)
        fsync_file(final)
        fsync_dir(os.path.dirname(final))
        return {"ok": True, "bytes": size, "sha256": sha, "receipt": receipt(final)}
    if op == "verify":
        path = safe(payload["path"])
        size, sha = digest(path)
        if size != int(payload["bytes"]) or sha != str(payload["sha256"]).lower():
            raise ValueError("archive_checksum_mismatch")
        return {"ok": True, "bytes": size, "sha256": sha, "receipt": receipt(path)}
    if op == "delete":
        path = safe(payload["path"])
        if os.path.islink(path) or not os.path.isfile(path):
            raise ValueError("archive_file_missing")
        old = receipt(path)
        os.unlink(path)
        fsync_dir(os.path.dirname(path))
        return {"ok": True, "receipt": old}
    raise ValueError("archive_operation_invalid")


def main() -> int:
    command = os.environ.get("SSH_ORIGINAL_COMMAND", "")
    if command.startswith("rsync "):
        try:
            args = shlex.split(command)
        except ValueError:
            print("archive rsync command invalid", file=sys.stderr)
            return 2
        if not args or args[0] != "rsync" or "--server" not in args or "--sender" in args:
            print("archive rsync mode refused", file=sys.stderr)
            return 2
        for argument in args[1:]:
            if argument.startswith("/") or ".." in argument or "\x00" in argument:
                print("archive rsync path refused", file=sys.stderr)
                return 2
        os.chdir(ROOT)
        os.execv("/usr/bin/rsync", args)
    if not command.startswith("dzn-archive-op "):
        print("archive operation refused", file=sys.stderr)
        return 2
    try:
        encoded = command.split(" ", 1)[1].strip()
        payload = json.loads(base64.urlsafe_b64decode(encoded + '=' * (-len(encoded) % 4)).decode("utf-8"))
        result = perform(payload)
        print(json.dumps(result, separators=(",", ":")))
        return 0
    except Exception as error:
        print(str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
