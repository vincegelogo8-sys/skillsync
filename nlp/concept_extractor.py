"""Offline research concepts; these selection scores are NOT adviser/RTA scores."""

import argparse
from collections import Counter
import json
import html
import os
from pathlib import Path
import re
import sys
import unicodedata

os.environ["HF_HUB_OFFLINE"] = "1"
os.environ["TRANSFORMERS_OFFLINE"] = "1"
os.environ["HF_HUB_DISABLE_TELEMETRY"] = "1"

ROOT = Path(__file__).resolve().parent
MODEL_PATH = ROOT / "models" / "all-MiniLM-L6-v2"
TAGGER_PATH = ROOT / "nltk_data" / "taggers" / "averaged_perceptron_tagger_eng"
SECTION_WEIGHTS = {
    "title": 1.0, "abstract": 0.8, "objectives": 0.8, "problem": 0.8,
    "background": 0.5, "scope": 0.5, "methodology": 0.5, "body": 0.5,
    "references": 0.0, "administrative": 0.0,
}
ALIASES = {
    "title": "title", "abstract": "abstract", "summary": "abstract",
    "objectives": "objectives", "research objectives": "objectives",
    "general objective": "objectives", "specific objectives": "objectives",
    "statement of the problem": "problem", "problem statement": "problem",
    "research questions": "problem", "introduction": "background",
    "background": "background", "background of the study": "background",
    "scope": "scope", "scope and limitations": "scope",
    "scope and delimitation": "scope", "methodology": "methodology",
    "research methodology": "methodology", "methods": "methodology",
    "references": "references", "bibliography": "references",
    "acknowledgements": "administrative", "acknowledgments": "administrative",
    "approval sheet": "administrative", "table of contents": "administrative",
    "dedication": "administrative",
    "authors": "administrative", "author information": "administrative",
    "proposed title": "title", "research title": "title",
    "researcher/s": "administrative", "researchers": "administrative",
    "priority agenda": "administrative", "group": "administrative",
    "course": "administrative", "significance": "background",
    "significance of the study": "background", "conceptual framework": "background",
    "research design": "methodology", "methodology/research design": "methodology",
    "expected outputs": "scope", "expected output/s": "scope",
    "name and signature of the student researchers": "administrative",
}
# Generic academic/administrative vocabulary, not a dictionary of research topics.
GENERIC = set("""study studies research project projects system systems proposed
proposal proposals develop development implementation objective objectives result
results researcher researchers chapter student students time approach method methods
methodology scope limitation limitations introduction background conclusion findings
paper work evaluation performance effectiveness accuracy efficiency user users
participant participants respondent respondents data information issue issues
problem problems process processes analysis design application applications
framework model models technique techniques tool tools platform platforms
author authors university college department school adviser supervisor semester
requirement requirements bachelor degree thesis capstone year page pages table
figure version test tests use uses solution solutions response responses
abstract references bibliography acknowledgements inspections inspection
facility facilities
deployment prototype prototypes
concept concepts topic topics
""".split())
BOUNDARY_WORDS = set("""identify categorize compare develop establish implement
evaluate investigate determine assess examine analyze analyse propose explore
design build create improve support provide enable measure collect detect
identify identifies develops compares extracts measures supports provides enables
improves uses using based proposed recurring various different existing new
willingness aim aims purpose purposes ability need needs intended intended
prevent prevents remove removes normalize normalizes reveal reveals
consider considers reduce reduces allow allows rely relies adapt adapts
apply applies summarize summarizes protect protects classify classifies
identifies categorizes evaluates investigates determines assesses examines
analyzes analyses proposes explores creates collects detects prioritizes
""".split())
QUALIFIERS = {"small", "large", "reliable", "timely", "various", "different", "redundant", "appropriate", "suitable"}
WEAK_HEADS = {"issues", "problems", "objectives", "results", "findings", "requirements"}
GENERIC_HEADS = {"model", "models", "system", "systems", "platform", "platforms",
                 "application", "applications", "method", "methods", "framework", "frameworks"}
ADMIN_LINE = re.compile(
    r"^(?:submitted\b|prepared\b|presented\b|in partial fulfillment\b|"
    r"by\s*:|authors?\s*:|advis[eo]r\s*:|supervisor\s*:|"
    r"(?:school|university|college|department|course|date|student id)\s*:|"
    r"copyright\b|all rights reserved\b|page\s+\d|chapter\s+\w+\s*$)", re.I
)
TOKEN = re.compile(r"[A-Za-z]+(?:[-'][A-Za-z]+)*|[0-9]+|[^\w\s]")


class LocalConfigurationError(RuntimeError):
    pass


def normalize_heading(text):
    heading = re.sub(r"^(?:\d+(?:\.\d+)*[.)]?|[IVX]+\.)\s+", "", text.strip()).lower().strip(" :#")
    if heading.startswith("priority agenda ("):
        return "priority agenda"
    return heading


def split_sections(proposal):
    """Accept plain extracted text or a section-name -> text mapping."""
    if isinstance(proposal, dict):
        sections = {}
        for name, text in proposal.items():
            if not isinstance(name, str) or not isinstance(text, str):
                raise ValueError("Section names and content must be strings.")
            key = ALIASES.get(normalize_heading(name), name if name in SECTION_WEIGHTS else "body")
            sections[key] = sections.get(key, "") + "\n" + text
        return sections
    if not isinstance(proposal, str):
        raise ValueError("Proposal must be text or a mapping of section names to text.")
    sections = {}
    current = "body"
    for line in proposal.splitlines():
        heading, colon, content = line.partition(":")
        key = ALIASES.get(normalize_heading(heading if colon else line))
        if key:
            current = key
            line = content if colon else ""
        sections[current] = sections.get(current, "") + "\n" + line
    return sections


def clean_section(text):
    text = unicodedata.normalize("NFKC", html.unescape(text)).replace("\u00ad", "")
    text = re.sub(r"(?<=[a-z])-\n(?=[a-z])", "", text)
    text = "\n".join(line for line in text.splitlines() if not ADMIN_LINE.match(line.strip()))
    counts = Counter(line.strip().lower() for line in text.splitlines())
    text = "\n".join(line for line in text.splitlines() if not (
        re.fullmatch(r"(?:page\s*)?\d+(?:\s*(?:of|/)\s*\d+)?|concept paper|\[page break\]", line.strip(), re.I)
        or re.search(r"\.{2,}\s*\d+\s*$", line)
        or (counts[line.strip().lower()] > 1 and len(line) < 160
            and re.search(r"university|college|republic of|department of|copyright", line, re.I))
    ))
    text = re.sub(r"https?://\S+|\b\S+@\S+\.\S+", " ", text)
    text = re.sub(r"\[[\d, ;\-]+\]|\([^()]*\b(?:19|20)\d{2}[a-z]?[^()]*\)", " ", text)
    return text.strip()


class ConceptExtractor:
    def __init__(self, model_path=MODEL_PATH, tagger_path=TAGGER_PATH):
        self.model_path = Path(model_path).resolve()
        if not (self.model_path / "modules.json").is_file():
            raise LocalConfigurationError(
                f"Local NLP model missing/incomplete: {self.model_path}. "
                "Run nlp/save_model.py first. No automatic download is allowed."
            )
        tagger_path = Path(tagger_path).resolve()
        if not all((tagger_path / f"averaged_perceptron_tagger_eng.{part}.json").is_file()
                   for part in ("weights", "tagdict", "classes")):
            raise LocalConfigurationError(
                f"Local English POS tagger missing/incomplete: {tagger_path}. "
                "Run nlp/setup_tagger.py during online setup. No automatic download is allowed."
            )
        from nltk.tag.perceptron import PerceptronTagger
        from nltk.stem import PorterStemmer
        from sklearn.feature_extraction.text import ENGLISH_STOP_WORDS
        from sentence_transformers import SentenceTransformer
        from keybert import KeyBERT

        try:
            self.model = SentenceTransformer(str(self.model_path), local_files_only=True, device="cpu")
            self.tagger = PerceptronTagger(loc=str(tagger_path))
        except (OSError, ValueError, LookupError) as exc:
            raise LocalConfigurationError(f"Cannot load local NLP assets: {exc}. Restore them using the setup scripts.") from exc
        self.keybert = KeyBERT(model=self.model)
        self.stop = set(ENGLISH_STOP_WORDS)
        self.stemmer = PorterStemmer()

    def candidate_phrases(self, text):
        """Contiguous noun groups only: no bridging across verbs or stop words."""
        phrases = []
        for sentence in re.split(r"[.!?;:\n]+", text):
            tokens = TOKEN.findall(sentence)
            tagged = self.tagger.tag(tokens)
            group = []

            def emit():
                if not group:
                    return
                # Noun head required; gerunds are allowed as nominal heads/modifiers.
                if not (group[-1][1].startswith("NN") or group[-1][1] == "VBG"):
                    return
                # Keep the full group plus a head-bearing suffix for long compounds.
                if len(group) > 5:
                    return
                sizes = [len(group)] if len(group) <= 3 else [len(group), 2, 3]
                for size in dict.fromkeys(sizes):
                    words = [w.lower() for w, _ in group[-size:]]
                    if words[-1] in WEAK_HEADS:
                        continue
                    if any(w in self.stop or w in BOUNDARY_WORDS for w in words):
                        continue
                    if all(w in GENERIC for w in words):
                        continue
                    if len(words) == 1 and (len(words[0]) < 3 or words[0] in GENERIC):
                        continue
                    if any(not re.fullmatch(r"[a-z]+(?:[-'][a-z]+)*", w) for w in words):
                        continue
                    phrases.append(" ".join(words))

            for word, tag in tagged + [(".", ".")]:
                lower = word.lower()
                noun_like = tag.startswith(("NN", "JJ")) or tag == "VBG"
                # Adjectives after a noun start a new group, not a sentence fragment.
                if tag.startswith("JJ") and group and group[-1][1].startswith("NN"):
                    emit()
                    group = []
                if noun_like and lower not in self.stop and lower not in BOUNDARY_WORDS and lower not in QUALIFIERS:
                    group.append((word, tag))
                else:
                    emit()
                    group = []
        return phrases

    def extract(self, proposal, max_concepts=10, min_semantic=0.25, diversity=0.15, diagnostics=False):
        """Return production keyword strings; scores require explicit diagnostics=True.

        Diagnostics are for developers only, never a recommendation/UI payload.
        Both modes use exactly the same internal selection and ordering logic.
        """
        if not 1 <= max_concepts <= 12:
            raise ValueError("max_concepts must be between 1 and 12.")
        if not 0 <= diversity <= 1 or not 0 <= min_semantic <= 1:
            raise ValueError("diversity and min_semantic must be between 0 and 1.")
        sections = {k: clean_section(v) for k, v in split_sections(proposal).items()
                    if SECTION_WEIGHTS[k] > 0}
        sections = {k: v for k, v in sections.items() if v}
        support = {}
        for name, text in sections.items():
            for phrase, count in Counter(self.candidate_phrases(text)).items():
                support.setdefault(phrase, {})[name] = count
        if not support:
            return {"concepts": []} if diagnostics else {"keywords": []}
        # Bounded embedding cost; section importance first, cross-section support second.
        phrases = sorted(support, key=lambda p: (
            -max(SECTION_WEIGHTS[s] for s in support[p]), -len(support[p]),
            -min(sum(support[p].values()), 3), -len(p.split()), p,
        ))[:256]

        import numpy as np
        from sklearn.feature_extraction.text import CountVectorizer

        chunks, owners = [], []
        for name, text in sections.items():
            ids = self.model.tokenizer.encode(text, add_special_tokens=False, truncation=False, verbose=False)
            # Below MiniLM's limit: all sections contribute, not just the opening 256 tokens.
            window = min(192, self.model.max_seq_length - 2)
            for start in range(0, len(ids), window):
                chunks.append(self.model.tokenizer.decode(ids[start:start + window]))
                owners.append(name)
        embeddings = self.model.encode(chunks, normalize_embeddings=True, show_progress_bar=False)
        section_vectors = np.array([embeddings[[i for i, owner in enumerate(owners) if owner == s]].mean(axis=0)
                                    for s in sections])
        document = np.average(section_vectors, axis=0, weights=[SECTION_WEIGHTS[s] for s in sections])
        document /= max(float(np.linalg.norm(document)), 1e-12)
        # Callable analyzer preserves complete candidates, including hyphenated terminology.
        vectorizer = CountVectorizer(analyzer=lambda _: phrases, vocabulary=phrases)
        phrase_vectors = self.model.encode(phrases, normalize_embeddings=True, show_progress_bar=False)
        ranked = self.keybert.extract_keywords(
            "\n".join(sections.values()), vectorizer=vectorizer,
            doc_embeddings=document.reshape(1, -1), word_embeddings=phrase_vectors,
            top_n=len(phrases), use_mmr=False,
        )
        indices = {p: i for i, p in enumerate(phrases)}
        rows = []
        for phrase, semantic in ranked:
            if semantic < min_semantic:
                continue
            importance = max(SECTION_WEIGHTS[s] for s in support[phrase])
            # Two distinct sections constitute full support; repetition in one does not.
            corroboration = min(len(support[phrase]) / 2, 1.0)
            score = 0.7 * semantic + 0.2 * importance + 0.1 * corroboration
            rows.append(dict(phrase=phrase, score=score, semantic=semantic,
                             section_importance=importance, support=corroboration,
                             sections=list(support[phrase]), index=indices[phrase]))
        # Prefer a fuller supported phrase over its nested fragment at similar relevance.
        def normalized_tokens(phrase):
            words = phrase.split()
            if len(words) > 1 and words[-1] in GENERIC_HEADS:
                words = words[:-1]
            return {self.stemmer.stem(w) for w in words}

        stems = {p: normalized_tokens(p) for p in phrases}
        rows = [r for r in rows if not any(
            stems[r["phrase"]] < stems[other["phrase"]]
            and other["score"] >= r["score"] - 0.08
            and (len(stems[r["phrase"]]) >= 2 or
                 float(phrase_vectors[r["index"]] @ phrase_vectors[other["index"]]) >= 0.75)
            for other in rows
        )]
        selected = []
        while rows and len(selected) < max_concepts:
            def similarity(row, other):
                return float(phrase_vectors[row["index"]] @ phrase_vectors[other["index"]])

            def utility(row):
                redundancy = max((max(0.0, similarity(row, old)) for old in selected), default=0.0)
                return (1 - diversity) * row["score"] - diversity * redundancy

            best = max(rows, key=lambda r: (utility(r), len(r["phrase"].split()), r["phrase"]))
            rows.remove(best)
            duplicate = False
            for old in selected:
                left, right = stems[best["phrase"]], stems[old["phrase"]]
                cosine = similarity(best, old)
                overlap = len(left & right) / len(left | right)
                nested = (left <= right or right <= left) and min(len(left), len(right)) >= 2
                if left == right or nested or cosine >= 0.92 or (cosine >= 0.8 and overlap >= 0.6):
                    duplicate = True
                    break
            if not duplicate:
                selected.append(best)
        selected.sort(key=lambda r: -r["score"])
        if not diagnostics:
            # This is the only production boundary: no scores, embeddings, or
            # diagnostics are passed to future SKILLSYNC consumers.
            return {"keywords": [r["phrase"] for r in selected]}
        result = {"concepts": [{"phrase": r["phrase"], "score": round(r["score"], 4)} for r in selected]}
        if diagnostics:
            result["diagnostics"] = {
                "model_path": str(self.model_path), "candidate_count": len(support),
                "embedded_candidates": len(phrases), "chunks": len(chunks),
                "section_weights": {s: SECTION_WEIGHTS[s] for s in sections},
                "signals": [{k: v for k, v in r.items() if k != "index"} for r in selected],
            }
        return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", type=Path, nargs="?", help="UTF-8 proposal text, or JSON section mapping")
    parser.add_argument("--stdin", action="store_true", help='Production JSON request on stdin: {"title": "...", "text": "..."}')
    parser.add_argument("--diagnostics", action="store_true",
                        help="Developer-only output with internal selection scores; never use for UI/RTA")
    args = parser.parse_args()
    if args.stdin and (args.input is not None or args.diagnostics):
        parser.error("--stdin is production-only and cannot be combined with a file or diagnostics")
    if not args.stdin and args.input is None:
        parser.error("Provide an input file or --stdin")
    try:
        if args.stdin:
            raw = sys.stdin.buffer.read(4_000_001)
            if len(raw) > 4_000_000:
                raise ValueError("Proposal input exceeds the local processing limit.")
            request = json.loads(raw.decode("utf-8-sig"))
            if (not isinstance(request, dict) or set(request) != {"title", "text"}
                    or not all(isinstance(request[k], str) for k in ("title", "text"))):
                raise ValueError("Expected a JSON object containing title and text strings.")
            proposal = split_sections(request["text"])
            proposal["title"] = request["title"]
            # Wrapped PDF lines and DOCX paragraph runs must not split noun groups.
            # Section boundaries have already been identified; no headings are lost.
            proposal = {name: re.sub(r"(?<!\n)\n(?!\n)", " ", clean_section(value))
                        for name, value in proposal.items()}
        else:
            content = args.input.read_text(encoding="utf-8-sig")
            proposal = json.loads(content) if args.input.suffix.lower() == ".json" else content
        print(json.dumps(ConceptExtractor().extract(proposal, diagnostics=args.diagnostics), indent=2))
    except (LocalConfigurationError, OSError, ValueError) as exc:
        parser.exit(1, f"{exc}\n")


if __name__ == "__main__":
    main()
