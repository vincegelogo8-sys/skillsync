"""Smoke-test improved research concept extraction using the local model only."""
from concept_extractor import ConceptExtractor, LocalConfigurationError

SAMPLE_PROPOSAL = """
Title: Research Adviser Recommendation System Using Skills Matching
Abstract
This research develops a web-based research adviser recommendation system.
Natural language processing extracts research concepts from student proposals.
Skills matching compares proposal topics with faculty research expertise.
TF-IDF and cosine similarity measure research topic alignment to support
appropriate adviser recommendations.
"""


def main():
    try:
        extractor = ConceptExtractor()
    except LocalConfigurationError as exc:
        raise SystemExit(str(exc)) from exc

    print("Local NLP model loaded successfully.")
    print(f"Model directory: {extractor.model_path}")
    result = extractor.extract(SAMPLE_PROPOSAL)
    if not result["keywords"]:
        raise RuntimeError("The sample proposal produced no research concepts.")
    print("Research concepts:")
    for keyword in result["keywords"]:
        print(f"  {keyword}")


if __name__ == "__main__":
    main()
