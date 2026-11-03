# Local NLP model

This standalone Python concept extractor uses KeyBERT with all-MiniLM-L6-v2 on CPU.
Laravel invokes its production STDIN interface through `LocalNlpService`.
See [Laravel integration](../docs/local-nlp-integration.md). Recommendation scoring
continues to use TF-IDF and cosine similarity, without any NLP numeric scores.

The improved pipeline also uses a small, local NLTK English POS tagger. See
[CONCEPT_EXTRACTION.md](CONCEPT_EXTRACTION.md) for setup, scoring, quality tests,
limits, and the JSON command-line interface. The existing model export is unchanged.

## Export the already downloaded model

From the project root in PowerShell:

```powershell
& .\nlp\.venv\Scripts\python.exe .\nlp\save_model.py
Test-Path .\nlp\models\all-MiniLM-L6-v2\modules.json
Get-ChildItem .\nlp\models\all-MiniLM-L6-v2
```

The exporter uses SentenceTransformer.save(), then reloads the saved model and
checks its 384-dimensional output. It reads the existing Hugging Face cache in
offline mode; it does not manually copy cache files or download anything. Initial
model/package downloads require internet on a fresh machine, but this machine's
existing cache is sufficient. An incomplete cache produces a setup error.

The portable standalone directory is `nlp/models/all-MiniLM-L6-v2/`, separate
from `nlp/.venv/`. Copy the entire model directory when moving the project.
The destination computer also needs compatible Python dependencies installed.

## Test with the internet disconnected

Disconnect Wi-Fi/Ethernet, then run from the project root:

```powershell
. .\nlp\.venv\Scripts\Activate.ps1
python .\nlp\test_keybert.py
python .\nlp\verify_offline.py
```

If PowerShell blocks activation, use the interpreter directly (no administrator
privileges, Developer Mode, or execution-policy change needed):

```powershell
& .\nlp\.venv\Scripts\python.exe .\nlp\test_keybert.py
& .\nlp\.venv\Scripts\python.exe .\nlp\verify_offline.py
```

Expect `Local NLP model loaded successfully.`, the resolved model directory,
and research concept text only (up to ten, without padding weak inputs). The verifier additionally prints
`Offline verification passed: empty HF cache, zero network attempts.` It uses a
temporary empty cache and a Python audit hook blocking socket connections, DNS
lookups, and datagram sends. It does not remove the real cache or change the
computer's network settings. Physically disconnecting remains a useful final
end-to-end check beyond this Python network guard.

The reusable extractor resolves its model path relative to its own script, sets
`HF_HUB_OFFLINE=1`, `TRANSFORMERS_OFFLINE=1`, and disables Hugging Face telemetry
before library imports. It loads the directory with `local_files_only=True`.
A missing/incomplete model produces an error, with no remote fallback.
No internet, Hugging Face token, OpenAI/GPT, API key, or API credits are needed.

## Source control and provenance

The virtual environment and Python bytecode caches are ignored. The model
directory is deliberately **not** ignored, and no commit is created by setup.
The exported model is approximately 87.35 MiB. I recommend distributing it as a
versioned model artifact separately from ordinary source commits, or using Git
LFS if it must be versioned with the project. Deploy the actual complete files
before running offline, not just LFS pointer files.

The [upstream model card](https://huggingface.co/sentence-transformers/all-MiniLM-L6-v2)
declares Apache-2.0. Preserve applicable license and attribution notices when
redistributing; the export includes the model card. The existing cached snapshot
used for this export is `1110a243fdf4706b3f48f1d95db1a4f5529b4d41`.

The supplied workspace had no `test_keybert.py`, so the test was created with a
sample adviser-recommendation research proposal. This validates extraction and
offline loading, not recommendation quality or full-proposal processing.
