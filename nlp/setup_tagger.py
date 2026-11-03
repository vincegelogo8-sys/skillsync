"""Explicit one-time ONLINE setup; never imported by the analyzer."""
from pathlib import Path
import nltk

if __name__ == "__main__":
    nltk.download("averaged_perceptron_tagger_eng",
                  download_dir=str(Path(__file__).resolve().parent / "nltk_data"),
                  raise_on_error=True)
