"""Repeatable offline quality checks and measured raw/improved comparisons."""
import json
from contextlib import redirect_stdout
from io import BytesIO, StringIO
from pathlib import Path
from tempfile import TemporaryDirectory
from time import perf_counter
from unittest.mock import patch
from types import SimpleNamespace

import concept_extractor
from concept_extractor import ConceptExtractor, LocalConfigurationError, MODEL_PATH, split_sections

ROOT = Path(__file__).resolve().parent
BAD_FRAGMENTS = {
    "topics faculty research", "research expertise tf", "proposal topics faculty",
    "research develops web", "identify categorize recurring",
    "willingness establish transparent", "issues design implement", "compare response",
}


def run_checks(extractor):
    assert extractor.model_path == MODEL_PATH.resolve()
    assert extractor.extract("") == {"keywords": []}
    assert extractor.extract("", diagnostics=True) == {"concepts": []}
    assert extractor.extract("The study and the research. A project. The students.") == {"keywords": []}
    assert extractor.extract({"References": "Quantum cryptography and teleportation."}) == {"keywords": []}
    assert split_sections("1. Abstract\nExample\nReferences\nCitation")["abstract"].strip() == "Example"
    candidates = extractor.candidate_phrases(
        "This research develops web applications. Proposal topics align with faculty research expertise. "
        "Identify and categorize recurring issues. Design and implement repair tracking. "
        "Compare response times and assess willingness to establish transparent governance."
    )
    assert not BAD_FRAGMENTS.intersection(candidates)
    assert "web applications" in candidates
    grammar = extractor.candidate_phrases("Fragmented damage reporting prevents timely maintenance prioritization.")
    assert all("prevents" not in phrase for phrase in grammar), grammar
    assert "maintenance prioritization" in grammar
    grammar = extractor.candidate_phrases("Data augmentation applies rotations and color transformations.")
    assert all("applies" not in phrase for phrase in grammar), grammar
    assert "data augmentation" in grammar
    technical = extractor.candidate_phrases(
        "Machine learning supports image classification. Deep learning improves computer vision. "
        "NLP supports sentiment analysis. IoT supports environmental monitoring. "
        "Blockchain supports cybersecurity. Cloud computing uses database systems."
    )
    assert {"machine learning", "deep learning", "nlp", "iot", "blockchain", "cybersecurity"} <= set(technical), technical
    short = extractor.extract("Title: Blockchain")
    assert short == {"keywords": ["blockchain"]}
    long = extractor.extract({
        "Background": "Distributed services store application events. " * 100,
        "Methodology": "Homomorphic encryption protects private computations.",
    }, diagnostics=True)
    assert long["diagnostics"]["chunks"] > 2
    assert "methodology" in long["diagnostics"]["section_weights"]
    assert any("encryption" in c["phrase"] for c in long["concepts"])
    with TemporaryDirectory(prefix="skillsync-config-test-") as temporary:
        for options, message in [({"model_path": temporary}, "model missing"),
                                 ({"tagger_path": temporary}, "tagger missing")]:
            try:
                ConceptExtractor(**options)
            except LocalConfigurationError as exc:
                assert message in str(exc)
                assert "No automatic download" in str(exc)
            else:
                raise AssertionError("Missing local asset was accepted")


def main():
    # Read the saved development results before overwriting timings. This guards
    # the output-only refactor against changes in selection, order, or scores.
    baseline = json.loads((ROOT / "quality_results.json").read_text(encoding="utf-8"))
    expected = {t["fixture"]: t["after"]["concepts"] for t in baseline["tests"]}
    started = perf_counter()
    extractor = ConceptExtractor()
    load_seconds = perf_counter() - started
    run_checks(extractor)
    reports = []
    for filename in ("maintenance.txt", "vision.txt", "security.txt"):
        text = (ROOT / "fixtures" / filename).read_text(encoding="utf-8")
        before_started = perf_counter()
        before = extractor.keybert.extract_keywords(text, keyphrase_ngram_range=(1, 3),
                                                    stop_words="english", top_n=10)
        before_seconds = perf_counter() - before_started
        after_started = perf_counter()
        after = extractor.extract(text, diagnostics=True)
        after_seconds = perf_counter() - after_started
        phrases = [c["phrase"] for c in after["concepts"]]
        assert after["concepts"] == expected[filename], (filename, "Selection or internal scores changed")
        production = extractor.extract(text)
        assert production == {"keywords": phrases}
        assert all(isinstance(keyword, str) for keyword in production["keywords"])
        assert "%" not in json.dumps(production)
        assert set(after) == {"concepts", "diagnostics"}
        assert all(set(c) == {"phrase", "score"} and isinstance(c["score"], float)
                   for c in after["concepts"])
        assert 5 <= len(phrases) <= 12, (filename, phrases)
        assert len(phrases) == len(set(phrases))
        assert not BAD_FRAGMENTS.intersection(phrases)
        assert all(not set(p.split()) <= extractor.stop for p in phrases)
        assert all(0 <= c["score"] <= 1 for c in after["concepts"])
        assert all(1 <= len(p.split()) <= 5 for p in phrases)
        assert "references" not in after["diagnostics"]["section_weights"]
        normalized = [{extractor.stemmer.stem(w) for w in p.split()} for p in phrases]
        assert not any(a <= b for i, a in enumerate(normalized) for j, b in enumerate(normalized)
                       if i != j and len(a) >= 2), phrases
        stems = {extractor.stemmer.stem(w) for p in phrases for w in p.split()}
        domain = {"maintenance.txt": {"mainten", "repair", "crowdsourc"},
                  "vision.txt": {"imag", "classif", "vision", "diseas"},
                  "security.txt": {"secur", "intrus", "network", "anomal"}}[filename]
        assert len(stems & domain) >= 2, (filename, phrases)
        # Excluded material must have no influence on candidates or scores.
        sections = split_sections(text)
        sections["references"] = "Unrelated quantum teleportation and financial forecasting."
        assert extractor.extract(sections) == production
        variants = {}
        for label, options in (("no_mmr", {"diversity": 0.0}),
                               ("higher_diversity", {"diversity": 0.3}),
                               ("higher_semantic_floor", {"min_semantic": 0.4})):
            variants[label] = extractor.extract(text, diagnostics=True, **options)["concepts"]
        reports.append({"fixture": filename, "raw_seconds": round(before_seconds, 3),
                        "improved_seconds": round(after_seconds, 3), "before": before, "after": after,
                        "settings_comparison": variants})
        print(f"\n{filename}: raw {before_seconds:.3f}s; improved {after_seconds:.3f}s")
        print("BEFORE:", "; ".join(p for p, _ in before))
        print("AFTER:", "; ".join(phrases))
    # Exercise the real CLI parser/serialization with the already loaded encoder.
    # Only construction is patched, so neither mode needs another model load.
    fixture = ROOT / "fixtures" / "maintenance.txt"
    for flags, expected_keys in (([], {"keywords"}), (["--diagnostics"], {"concepts", "diagnostics"})):
        stdout = StringIO()
        with patch.object(concept_extractor, "ConceptExtractor", return_value=extractor), \
                patch("sys.argv", ["concept_extractor.py", str(fixture), *flags]), redirect_stdout(stdout):
            concept_extractor.main()
        payload = json.loads(stdout.getvalue())
        assert set(payload) == expected_keys
        if not flags:
            assert payload == {"keywords": [c["phrase"] for c in expected["maintenance.txt"]]}
            assert "%" not in stdout.getvalue()
        else:
            assert payload["concepts"] == expected["maintenance.txt"]
    print("Production/debug output contracts passed; internal scores and concept selection unchanged.")
    request = {"title": "Crowdsourced School Infrastructure Maintenance", "text": fixture.read_text(encoding="utf-8")}
    stdout = StringIO()
    with patch.object(concept_extractor, "ConceptExtractor", return_value=extractor), \
            patch("sys.argv", ["concept_extractor.py", "--stdin"]), \
            patch("sys.stdin", SimpleNamespace(buffer=BytesIO(json.dumps(request).encode("utf-8")))), redirect_stdout(stdout):
        concept_extractor.main()
    assert json.loads(stdout.getvalue()) == {"keywords": [c["phrase"] for c in expected["maintenance.txt"]]}
    for invalid in ({"text": "Missing title"}, {"title": "Title", "text": ["Wrong type"]},
                    {"title": "Title", "text": "Text", "diagnostics": True}):
        with patch.object(concept_extractor, "ConceptExtractor") as constructor, \
                patch("sys.argv", ["concept_extractor.py", "--stdin"]), \
                patch("sys.stdin", SimpleNamespace(buffer=BytesIO(json.dumps(invalid).encode("utf-8")))), \
                patch("sys.stderr", StringIO()):
            try:
                concept_extractor.main()
            except SystemExit as exc:
                assert exc.code == 1
            else:
                raise AssertionError("Invalid STDIN request accepted")
            constructor.assert_not_called()
    print("Laravel STDIN production contract and invalid-input checks passed.")
    report = {"model_load_seconds": round(load_seconds, 3), "tests": reports}
    (ROOT / "quality_results.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(f"\nAll quality checks passed. Model/import initialization: {load_seconds:.3f}s.")
    print("Measured results saved to nlp/quality_results.json")


if __name__ == "__main__":
    main()
