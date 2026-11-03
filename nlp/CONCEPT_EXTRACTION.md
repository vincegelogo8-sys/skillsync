# Offline research concept extraction

`ConceptExtractor` accepts either whole extracted proposal text or a mapping of
section names to text, and normally returns `{"keywords": ["machine learning", "image classification"]}`.
Production output contains only the selected strings, in ranked order. Internal
scores remain inside Python for selection, filtering, and deduplication; they are
never converted to percentages or passed into adviser recommendation scoring.
Laravel now consumes this output through `LocalNlpService`; TF-IDF and cosine
similarity remain the recommendation algorithm. See [integration documentation](../docs/local-nlp-integration.md).

Explicit development mode (`extract(proposal, diagnostics=True)` or CLI
`--diagnostics`) returns `{"concepts": [{"phrase": "...", "score": 0.7}], "diagnostics": {...}}`.
These developer-only internal selection scores must never be displayed to students,
faculty, or administrators, or combined with RTA. Laravel consumes the default
`keywords` output and rejects additional fields, including diagnostic scores. Empty input
normally returns `{"keywords": []}`; diagnostics mode returns `{"concepts": []}`.

## Running it

From the project root, with the existing Python 3.11 environment:

```powershell
# One proposal -> JSON on stdout; model-loading progress goes to stderr.
& .\nlp\.venv\Scripts\python.exe .\nlp\concept_extractor.py .\nlp\fixtures\maintenance.txt

# Explicit developer-only debug output: internal scores and section/chunk counts.
& .\nlp\.venv\Scripts\python.exe .\nlp\concept_extractor.py .\nlp\fixtures\vision.txt --diagnostics

# Original sample, now using the improved extractor.
& .\nlp\.venv\Scripts\python.exe .\nlp\test_keybert.py

# All three before/after comparisons, with an empty HF cache and socket guard.
& .\nlp\.venv\Scripts\python.exe .\nlp\verify_offline.py --quality
```

For your own document, pass a UTF-8 `.txt` file containing extracted text, or a
`.json` file containing a section-name-to-text object. PDF/DOCX document extraction
remains in Laravel. The `--stdin` production interface accepts
UTF-8 JSON with exactly `title` and `text` strings, and cannot be combined with
`--diagnostics`. It retains section boundaries, then joins wrapped lines within
each section for PDF/DOCX consistency. Disconnect Wi-Fi/Ethernet
and repeat the commands to verify physical offline operation.

For repeated work in one Python process, instantiate `ConceptExtractor()` once
and call `.extract(proposal)` for each proposal. The encoder, POS tagger, and
KeyBERT wrapper are reused. `max_concepts` defaults to 10 and can be 1–12; fewer
results are returned if insufficient candidates pass. Nothing is padded to five.

## Additional setup (already completed on this computer)

Only `nltk==3.9.2` was added to the existing environment. Its dependencies were
already installed. This supplies a small averaged-perceptron English POS tagger,
not another transformer or an external API. NLTK's supported tagger API accepts
a local model location: https://www.nltk.org/api/nltk.tag.perceptron.html

The English tagger is stored under
`nlp/nltk_data/taggers/averaged_perceptron_tagger_eng/`. Its three JSON files total
approximately 5.44 MiB; the downloaded archive is about 1.47 MiB. Keep those JSON
files with the project when moving it. No Punkt, WordNet, stopword corpus, or
additional transformer download is required.

On another prepared Python environment, these are explicit online setup commands:

```powershell
& .\nlp\.venv\Scripts\python.exe -m pip install -r .\nlp\requirements-concepts.txt
& .\nlp\.venv\Scripts\python.exe .\nlp\setup_tagger.py
```

Normal extraction never calls the downloader. Missing model/tagger files produce
a local configuration error. MiniLM still loads only from
`nlp/models/all-MiniLM-L6-v2/`, with `local_files_only=True`; offline flags and
telemetry disabling are set before library imports. No HF token, Hub connection,
OpenAI/GPT, API key, credits, or internet connection is required at runtime.

## Pipeline and rationale

1. Recognize explicit section headings (including common numbered headings and
   `Title: ...` inline forms), or accept a section mapping. Unrecognized sections
   remain ordinary body text. Normalize Unicode, PDF-style line hyphenation,
   numeric/author-year citations and URLs; exclude labelled author/admin lines.
2. Tag tokens in their original sentence context. Build contiguous adjective/noun
   groups with a noun or nominal-gerund head. Verbs, punctuation, prepositions,
   stop words, and line boundaries break groups rather than being deleted and
   joining their neighbors. A small domain-independent list of academic action
   verbs guards known POS ambiguity. Adjectives following nouns start new groups.
3. Keep noun groups of 1–5 words. For four/five-word compounds, also consider
   two/three-word head suffixes. Do not enumerate every adjacent n-gram. Reject
   all-generic phrases, weak heads, boilerplate, and short meaningless tokens.
   Generic words remain legal inside informative phrases: e.g. a system name.
   Technical single words and acronyms are allowed; there is no IT-topic whitelist.
4. Bound the candidate pool to 256, preferring important sections and independent
   support. Encode candidates once. Tokenize every included section into at most
   192-wordpiece chunks; average chunk embeddings within each section, then weight
   the section vectors. This avoids silently using only the proposal opening.
5. Pass the vetted candidate vectorizer and precomputed document/candidate
   embeddings into KeyBERT. Request the entire bounded candidate pool, not raw
   top ten. Keep semantic relevance at least 0.25, then score and deduplicate.
   KeyBERT documents these custom vectorizer/embedding inputs at
   https://maartengr.github.io/KeyBERT/api/keybert.html

## Section weights

| Section | Weight | Reason |
| --- | ---: | --- |
| Title | 1.0 | Most concise declaration of the topic |
| Abstract, objectives, problem statement | 0.8 | Intent and concrete research questions |
| Background/introduction, scope, methodology, ordinary body | 0.5 | Supporting context; technical methodology phrases still pass quality filters |
| References, acknowledgements, administrative sections | 0 | Do not describe the research contribution reliably |

Weights affect the pooled document embedding and a candidate's section-importance
signal. No text is duplicated. Section averages stop a long background from
dominating through length; a title boost does not guarantee every title word wins.

## Selection score and deduplication

`score = 0.70 * semantic_relevance + 0.20 * section_importance + 0.10 * support`

- Semantic relevance is KeyBERT's cosine between the candidate and the weighted
  document embedding. It is the primary selection signal.
- Section importance is the highest weight of a section containing the candidate.
- Support is 0.5 for one section, 1.0 for two or more distinct sections. Repeating
  text many times in a single section cannot increase this score.
- Grammar/phrase quality are eligibility gates rather than opaque score bonuses.
  Specificity is handled by preferring informative expanded phrases over fragments.

Stemmed token sets normalize plural/inflection variants. Generic terminal heads
such as "models" are ignored for duplicate comparison only. A fuller phrase can
replace its nested variant when its score is within 0.08; for single-word nesting,
cosine must also be at least 0.75. Selection rejects nested multiword variants,
identical normalized sets, cosine >= 0.92, or token Jaccard >= 0.60 together with
cosine >= 0.80. Thus shared domain vocabulary alone is not a duplicate.

MMR-style reranking uses `0.85 * score - 0.15 * maximum_selected_similarity`.
It is applied after the transparent score (rather than KeyBERT's built-in MMR,
which would ignore the additional signals). Scores returned only in diagnostics
mode remain the component scores, not this changing selection utility. These are documented starting
heuristics, not statistically calibrated or universally optimal thresholds.

## Tests and measured comparisons

`test_concepts.py` uses school maintenance, plant-disease computer vision, and
cloud network security. It preserves the former raw KeyBERT configuration as the
baseline: 1–3 grams, English stop words, top ten, no MMR. Both pipelines receive
the same complete input. Consequently the raw baseline retains its original
truncation and heading-contamination weaknesses; this is not an isolated ablation
of candidate generation alone.

The suite checks the production keywords-only schema, explicit diagnostic score
output, and identical concept selection/order/internal scores against the saved
development report before updating it. It exercises both CLI modes using one
loaded model. The suite also checks nonempty domain coverage, 5–12 concepts on the representative
fixtures, duplicate/stopword/fragment rejection, meaningful short input without
padding, acronym/single-word preservation, ignored references, missing local
assets, and a late methodology concept after a long background. It also compares
diversity 0, 0.15, and 0.30, and semantic floors 0.25 and 0.40. The default retains
moderate relevance-focused diversity; a higher floor can lose useful secondary
concepts. All setting outputs and per-concept signals are available for review.

`quality_results.json` is a developer-only test artifact generated by the repeatable suite and contains full
before/after outputs, setting variants, initialization time, and per-proposal CPU
times. Timings are single observations after model loading, not a benchmark
distribution; the baseline runs first and may incur extra warm-up cost.

The offline verifier uses an empty temporary HF cache, removes token/API-key
environment variables, and blocks/records Python socket connect, DNS and datagram
send events. It leaves real caches and computer network settings alone. This is
strong evidence for the exercised Python paths; physical disconnection is the
final end-to-end check.

## Remaining limitations

This English POS tagger is imperfect on unusual grammar and unlabelled names.
Unrecognized headings, tables, broken PDF layout, and unlabelled author details
may still need upstream cleanup. Contiguous noun groups can miss concepts joined
by prepositions, and a five-word limit rejects long nominal expressions. The
bounded candidate pool can omit niche phrases in very large proposals. Semantic
deduplication can occasionally merge related but distinct ideas, or miss synonyms.
Three synthetic fixtures establish regressions, not expert-validated extraction
accuracy. Real proposals and human concept annotations are the next evaluation
step; no recommendation-quality or RTA claim follows from these tests.
