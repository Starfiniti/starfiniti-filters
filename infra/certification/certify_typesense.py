#!/usr/bin/env python3
"""Fail-closed Typesense 30.2 service and least-privilege certification probe."""

from __future__ import annotations

import argparse
import json
import os
import platform
import re
import secrets
import stat
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any


class CertificationError(RuntimeError):
    pass


@dataclass
class ApiResponse:
    status: int
    body: Any


class TypesenseClient:
    def __init__(self, base_url: str, api_key: str | None, timeout: float) -> None:
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.timeout = timeout

    def request(
        self,
        method: str,
        path: str,
        *,
        payload: Any | None = None,
        raw_payload: bytes | None = None,
        content_type: str = "application/json",
        expected: tuple[int, ...] = (200,),
    ) -> ApiResponse:
        if payload is not None and raw_payload is not None:
            raise ValueError("payload and raw_payload are mutually exclusive")

        data = raw_payload
        if payload is not None:
            data = json.dumps(payload, separators=(",", ":")).encode("utf-8")

        headers = {"Accept": "application/json"}
        if data is not None:
            headers["Content-Type"] = content_type
        if self.api_key:
            headers["X-TYPESENSE-API-KEY"] = self.api_key

        request = urllib.request.Request(
            f"{self.base_url}{path}", data=data, headers=headers, method=method
        )

        try:
            # base_url is constrained by loopback_url before this client is created.
            # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                status_code = response.status
                body_bytes = response.read()
        except urllib.error.HTTPError as error:
            status_code = error.code
            body_bytes = error.read(4096)
        except (urllib.error.URLError, TimeoutError) as error:
            raise CertificationError(f"request failed: {method} {path}: {error}") from error

        body_text = body_bytes.decode("utf-8", errors="replace")
        try:
            body: Any = json.loads(body_text) if body_text else None
        except json.JSONDecodeError:
            body = body_text

        if status_code not in expected:
            bounded = body_text[:500].replace("\n", " ")
            raise CertificationError(
                f"unexpected HTTP {status_code} for {method} {path}: {bounded}"
            )

        return ApiResponse(status=status_code, body=body)


def require(condition: bool, message: str) -> None:
    if not condition:
        raise CertificationError(message)


def sha256_digest(value: str) -> str:
    if not re.fullmatch(r"sha256:[a-f0-9]{64}", value):
        raise argparse.ArgumentTypeError("artifact digest must be sha256 followed by 64 lowercase hexadecimal characters")
    return value


def loopback_url(value: str) -> str:
    parsed = urllib.parse.urlsplit(value)
    if parsed.scheme not in {"http", "https"} or parsed.hostname not in {"127.0.0.1", "::1", "localhost"}:
        raise argparse.ArgumentTypeError("certification URL must use HTTP(S) on loopback")
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise argparse.ArgumentTypeError("certification URL must not contain credentials, a query, or a fragment")
    return value.rstrip("/")


def read_secret(path: Path) -> str:
    file_stat = path.stat()
    if os.name == "posix" and stat.S_IMODE(file_stat.st_mode) & 0o077:
        raise CertificationError(f"secret file must not be group/world accessible: {path}")
    value = path.read_text(encoding="utf-8").strip()
    require(len(value) >= 32, "Typesense admin key must contain at least 32 characters")
    return value


def write_evidence(path: Path, evidence: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(evidence, handle, indent=2, sort_keys=True)
            handle.write("\n")
        os.replace(temporary_name, path)
    finally:
        if os.path.exists(temporary_name):
            os.unlink(temporary_name)


def create_key(admin: TypesenseClient, description: str, actions: list[str], collections: list[str]) -> dict[str, Any]:
    requested_value = secrets.token_urlsafe(36)
    response = admin.request(
        "POST",
        "/keys",
        payload={
            "description": description,
            "actions": actions,
            "collections": collections,
            "value": requested_value,
        },
        expected=(201,),
    ).body
    require(isinstance(response, dict) and isinstance(response.get("id"), int), "key creation omitted numeric id")
    return {"id": response["id"], "value": requested_value}


def run(args: argparse.Namespace) -> dict[str, Any]:
    admin_key = read_secret(args.admin_key_file)
    admin = TypesenseClient(args.url, admin_key, args.timeout)
    anonymous = TypesenseClient(args.url, None, args.timeout)
    suffix = f"{int(time.time())}_{os.getpid()}_{secrets.token_hex(3)}"
    collection_a = f"sfs_cert_a_{suffix}"
    collection_b = f"sfs_cert_b_{suffix}"
    alias = f"sfs_cert_live_{suffix}"
    synonym_set = f"sfs_cert_synonyms_{suffix}"
    curation_set = f"sfs_cert_curations_{suffix}"
    created_keys: list[int] = []
    created_collections: list[str] = []
    tests: list[str] = []
    cleanup_errors: list[str] = []

    def passed(name: str) -> None:
        tests.append(name)

    schema_fields = [
        {"name": "title", "type": "string"},
        {"name": "sku", "type": "string"},
        {"name": "visibility", "type": "string", "facet": True},
        {"name": "locale", "type": "string", "facet": True},
        {"name": "channel", "type": "string", "facet": True},
        {"name": "customer_scope", "type": "string", "facet": True},
        {"name": "price_minor", "type": "int32", "sort": True},
    ]

    try:
        health = anonymous.request("GET", "/health").body
        require(isinstance(health, dict) and health.get("ok") is True, "health endpoint is not OK")
        passed("anonymous_health")

        debug = admin.request("GET", "/debug").body
        require(isinstance(debug, dict), "debug endpoint did not return an object")
        server_version = str(debug.get("version", ""))
        require(server_version == args.expected_version, f"expected Typesense {args.expected_version}, got {server_version!r}")
        passed("exact_server_version")

        search_key = create_key(admin, f"cert search {suffix}", ["documents:search"], [collection_a])
        created_keys.append(search_key["id"])
        index_key = create_key(
            admin,
            f"cert indexing {suffix}",
            ["collections:get", "documents:import", "documents:upsert", "documents:delete"],
            [collection_a, collection_b],
        )
        created_keys.append(index_key["id"])
        provision_key = create_key(
            admin,
            f"cert provisioning {suffix}",
            ["collections:*", "aliases:*"],
            [collection_a, collection_b],
        )
        created_keys.append(provision_key["id"])
        relevance_key = create_key(
            admin,
            f"cert relevance {suffix}",
            ["synonym_sets:*", "synonym_sets/items:*", "curation_sets:*", "curation_sets/items:*"],
            ["*"],
        )
        created_keys.append(relevance_key["id"])
        passed("separate_least_privilege_keys_created")

        search = TypesenseClient(args.url, search_key["value"], args.timeout)
        indexer = TypesenseClient(args.url, index_key["value"], args.timeout)
        provisioner = TypesenseClient(args.url, provision_key["value"], args.timeout)
        relevance = TypesenseClient(args.url, relevance_key["value"], args.timeout)

        for collection_name in (collection_a, collection_b):
            provisioner.request(
                "POST",
                "/collections",
                payload={"name": collection_name, "fields": schema_fields},
                expected=(201,),
            )
            created_collections.append(collection_name)
        passed("collection_creation")

        documents = [
            {"id": "public-1", "title": "Trail Shoe", "sku": "SKU-EXACT-1", "visibility": "public", "locale": "sl-SI", "channel": "web", "customer_scope": "public", "price_minor": 12999},
            {"id": "public-2", "title": "Road Shoe", "sku": "SKU-2", "visibility": "public", "locale": "sl-SI", "channel": "web", "customer_scope": "public", "price_minor": 9999},
            {"id": "forbidden-1", "title": "Secret Shoe", "sku": "SECRET-1", "visibility": "hidden", "locale": "sl-SI", "channel": "web", "customer_scope": "private", "price_minor": 1},
            {"id": "invalid-1", "title": "Invalid", "sku": "INVALID", "visibility": "public", "locale": "sl-SI", "channel": "web", "customer_scope": "public", "price_minor": "not-an-integer"},
        ]
        ndjson = "\n".join(json.dumps(document, separators=(",", ":")) for document in documents).encode("utf-8") + b"\n"
        import_response = indexer.request(
            "POST",
            f"/collections/{collection_a}/documents/import?action=upsert",
            raw_payload=ndjson,
            content_type="text/plain",
        ).body
        require(isinstance(import_response, str), "import endpoint did not return NDJSON")
        import_results = [json.loads(line) for line in import_response.splitlines() if line.strip()]
        require(len(import_results) == 4, "import did not return one result per document")
        require(sum(result.get("success") is False for result in import_results) == 1, "partial import failure was not represented exactly once")
        passed("per_document_partial_import")

        query = urllib.parse.urlencode(
            {
                "q": "SKU-EXACT-1",
                "query_by": "sku,title",
                "filter_by": "visibility:=public && locale:=sl-SI && channel:=web && customer_scope:=public",
            }
        )
        result = search.request("GET", f"/collections/{collection_a}/documents/search?{query}").body
        hits = result.get("hits", []) if isinstance(result, dict) else []
        require(hits and hits[0].get("document", {}).get("id") == "public-1", "exact SKU was not top-1")
        require(all(hit.get("document", {}).get("id") != "forbidden-1" for hit in hits), "forbidden document leaked")
        passed("exact_sku_and_mandatory_filter")

        search.request("GET", "/keys", expected=(401,))
        passed("search_key_cannot_administer")

        provisioner.request("PUT", f"/aliases/{alias}", payload={"collection_name": collection_a})
        alias_state = provisioner.request("GET", f"/aliases/{alias}").body
        require(alias_state.get("collection_name") == collection_a, "initial alias target mismatch")
        provisioner.request("PUT", f"/aliases/{alias}", payload={"collection_name": collection_b})
        alias_state = provisioner.request("GET", f"/aliases/{alias}").body
        require(alias_state.get("collection_name") == collection_b, "alias activation mismatch")
        provisioner.request("PUT", f"/aliases/{alias}", payload={"collection_name": collection_a})
        alias_state = provisioner.request("GET", f"/aliases/{alias}").body
        require(alias_state.get("collection_name") == collection_a, "alias rollback mismatch")
        passed("alias_activation_and_rollback")

        relevance.request(
            "PUT",
            f"/synonym_sets/{synonym_set}",
            payload={"items": [{"id": "shoe", "synonyms": ["shoe", "sneaker"]}]},
        )
        synonym_state = relevance.request("GET", f"/synonym_sets/{synonym_set}").body
        require(synonym_state.get("name") == synonym_set, "synonym set read-after-write failed")
        passed("v30_synonym_set")

        relevance.request(
            "PUT",
            f"/curation_sets/{curation_set}",
            payload={
                "items": [
                    {
                        "id": "pin-public",
                        "rule": {"query": "shoe", "match": "exact"},
                        "includes": [{"id": "public-2", "position": 1}],
                        "filter_curated_hits": True,
                    }
                ]
            },
        )
        curation_state = relevance.request("GET", f"/curation_sets/{curation_set}").body
        require(curation_state.get("name") == curation_set, "curation set read-after-write failed")
        passed("v30_curation_set")

        return {
            "status": "passed",
            "recorded_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "server_version": server_version,
            "expected_version": args.expected_version,
            "artifact": {
                "kind": args.artifact_kind,
                "digest": args.artifact_digest,
            },
            "platform": {"system": platform.system(), "release": platform.release(), "machine": platform.machine()},
            "tests": tests,
            "cleanup_errors": cleanup_errors,
        }
    finally:
        for path in (
            f"/aliases/{alias}",
            f"/synonym_sets/{synonym_set}",
            f"/curation_sets/{curation_set}",
        ):
            try:
                admin.request("DELETE", path, expected=(200, 404))
            except Exception as error:  # cleanup must not hide the primary failure
                cleanup_errors.append(f"{path}: {error}")
        for collection_name in reversed(created_collections):
            try:
                admin.request("DELETE", f"/collections/{collection_name}", expected=(200, 404))
            except Exception as error:
                cleanup_errors.append(f"collection {collection_name}: {error}")
        for key_id in reversed(created_keys):
            try:
                admin.request("DELETE", f"/keys/{key_id}", expected=(200, 404))
            except Exception as error:
                cleanup_errors.append(f"key {key_id}: {error}")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", type=loopback_url, default="http://127.0.0.1:8108")
    parser.add_argument("--admin-key-file", type=Path, required=True)
    parser.add_argument("--expected-version", default="30.2")
    parser.add_argument(
        "--artifact-kind",
        choices=("container-image", "standalone-binary"),
        default="container-image",
    )
    parser.add_argument(
        "--artifact-digest",
        type=sha256_digest,
        default="sha256:610f2d34b1f93d00762869da2c67736775e5798d19a2c8b91b014b8a0cc1e110",
    )
    parser.add_argument("--timeout", type=float, default=10.0)
    parser.add_argument("--output", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        evidence = run(args)
        if evidence.get("cleanup_errors"):
            raise CertificationError("certification resources could not be fully cleaned up")
        write_evidence(args.output, evidence)
        print(json.dumps({"status": "passed", "evidence": str(args.output), "tests": len(evidence["tests"])}))
        return 0
    except Exception as error:
        print(json.dumps({"status": "failed", "error": str(error)[:1000]}), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
