"""Run smoke/quality checks with an empty HF cache and blocked networking."""

import argparse
import os
from pathlib import Path
import runpy
import sys
from tempfile import TemporaryDirectory


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--quality", action="store_true", help="Run all three domain comparisons")
    mode.add_argument("--stdin", action="store_true", help="Verify the production STDIN bridge; JSON only on stdout")
    args = parser.parse_args()
    network_attempts = []

    def block_network(event, args):
        if event in {"socket.connect", "socket.getaddrinfo", "socket.sendto"}:
            network_attempts.append(event)
            raise RuntimeError(f"Offline verification blocked networking: {event}")

    # The hook remains active for this verification process only.
    sys.addaudithook(block_network)
    with TemporaryDirectory(prefix="skillsync-offline-") as empty_cache:
        for variable in (
            "HF_HOME", "HF_HUB_CACHE", "HUGGINGFACE_HUB_CACHE",
            "TRANSFORMERS_CACHE", "SENTENCE_TRANSFORMERS_HOME",
        ):
            os.environ[variable] = empty_cache
        for variable in ("HF_TOKEN", "HUGGING_FACE_HUB_TOKEN", "OPENAI_API_KEY"):
            os.environ.pop(variable, None)
        os.environ["HF_HUB_DISABLE_IMPLICIT_TOKEN"] = "1"
        target = "concept_extractor.py" if args.stdin else ("test_concepts.py" if args.quality else "test_keybert.py")
        if args.stdin:
            sys.argv = [target, "--stdin"]
        runpy.run_path(
            str(Path(__file__).resolve().with_name(target)),
            run_name="__main__",
        )
        if network_attempts:
            raise RuntimeError(f"Network attempts detected: {network_attempts}")
    print("Offline verification passed: empty HF cache, zero network attempts.", file=sys.stderr if args.stdin else sys.stdout)


if __name__ == "__main__":
    main()
