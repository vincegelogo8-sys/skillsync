"""Export the already cached model as a standalone Sentence-Transformers model."""

import os
from pathlib import Path

# Setup uses the existing cache only; an incomplete cache must fail, not download.
os.environ["HF_HUB_OFFLINE"] = "1"
os.environ["HF_HUB_DISABLE_TELEMETRY"] = "1"
os.environ["TRANSFORMERS_OFFLINE"] = "1"


def main():
    from sentence_transformers import SentenceTransformer

    model_path = Path(__file__).resolve().parent / "models" / "all-MiniLM-L6-v2"
    try:
        model = SentenceTransformer(
            "sentence-transformers/all-MiniLM-L6-v2",
            local_files_only=True,
            device="cpu",
        )
    except OSError as exc:
        raise SystemExit(
            "Cannot load the cached all-MiniLM-L6-v2 model. Complete its initial "
            "download during online setup, then run nlp/save_model.py again. "
            f"No download was attempted. Details: {exc}"
        ) from exc

    model_path.mkdir(parents=True, exist_ok=True)
    model.save(str(model_path))
    # Verify that the export can be loaded by path, without the model identifier.
    saved_model = SentenceTransformer(
        str(model_path), local_files_only=True, device="cpu"
    )
    embedding = saved_model.encode("Research concept extraction")
    if embedding.shape != (384,):
        raise RuntimeError(f"Unexpected embedding shape: {embedding.shape}")
    print(f"Standalone NLP model saved and reloaded successfully: {model_path}")


if __name__ == "__main__":
    main()
