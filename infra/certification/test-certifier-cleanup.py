#!/usr/bin/env python3
"""Regression test for partial Typesense key-creation cleanup."""

from __future__ import annotations

import importlib.util
import os
import sys
import tempfile
from pathlib import Path
from types import SimpleNamespace


MODULE_PATH = Path(__file__).with_name("certify_typesense.py")
SPEC = importlib.util.spec_from_file_location("starfiniti_certifier", MODULE_PATH)
if SPEC is None or SPEC.loader is None:
    raise SystemExit("could not load certifier module")
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class FailingKeyClient:
    key_creations = 0
    deleted_key_ids: list[int] = []

    def __init__(self, base_url: str, api_key: str | None, timeout: float) -> None:
        del base_url, api_key, timeout

    def request(self, method: str, path: str, **kwargs: object) -> object:
        del kwargs
        if method == "GET" and path == "/health":
            return MODULE.ApiResponse(200, {"ok": True})
        if method == "GET" and path == "/debug":
            return MODULE.ApiResponse(200, {"version": "30.2"})
        if method == "POST" and path == "/keys":
            type(self).key_creations += 1
            if type(self).key_creations == 4:
                raise MODULE.CertificationError("injected fourth-key failure")
            return MODULE.ApiResponse(201, {"id": type(self).key_creations})
        if method == "DELETE" and path.startswith("/keys/"):
            type(self).deleted_key_ids.append(int(path.rsplit("/", 1)[1]))
            return MODULE.ApiResponse(200, {})
        if method == "DELETE":
            return MODULE.ApiResponse(404, {})
        raise AssertionError(f"unexpected fake request: {method} {path}")


def main() -> int:
    original_client = MODULE.TypesenseClient
    with tempfile.TemporaryDirectory(prefix="starfiniti-certifier-test-") as temporary_directory:
        secret_path = Path(temporary_directory) / "admin-key"
        secret_path.write_text("a" * 64, encoding="utf-8")
        os.chmod(secret_path, 0o600)
        args = SimpleNamespace(
            admin_key_file=secret_path,
            url="http://127.0.0.1:8108",
            timeout=1.0,
            expected_version="30.2",
            artifact_kind="container-image",
            artifact_digest="sha256:" + "0" * 64,
        )
        MODULE.TypesenseClient = FailingKeyClient
        try:
            try:
                MODULE.run(args)
            except MODULE.CertificationError as error:
                if str(error) != "injected fourth-key failure":
                    raise
            else:
                raise AssertionError("injected key-creation failure did not propagate")
        finally:
            MODULE.TypesenseClient = original_client

    if FailingKeyClient.deleted_key_ids != [3, 2, 1]:
        raise AssertionError(f"partial keys were not cleaned in reverse order: {FailingKeyClient.deleted_key_ids}")
    print("typesense-certifier-partial-key-cleanup-ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
