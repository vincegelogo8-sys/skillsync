# Measured concept extraction results

Developer-only diagnostic report: numeric values below are internal concept
selection scores. Production output exposes only `{"keywords": ["..."]}`.
Never display these scores in SKILLSYNC or use them in recommendation/RTA scoring.

All three fixtures passed the quality suite with an empty Hugging Face cache and zero recorded network attempts.

One-time model/import initialization: 24.512 seconds.
Per-proposal times exclude initialization. These are single CPU observations; other system activity and warm-up affect them.

Concept scores select phrases only; they are not RTA percentages or confidence probabilities.

## maintenance.txt

Raw KeyBERT: 1.298s. Improved extractor: 0.254s.

| Raw KeyBERT concept | Improved concept | Selection score |
| --- | --- | ---: |
| school infrastructure maintenance | crowdsourced school infrastructure maintenance | 0.8526 |
| crowdsourced school infrastructure | maintenance prioritization | 0.6173 |
| crowdsourced school | crowdsourcing platform | 0.5735 |
| infrastructure maintenance | damage reporting | 0.5714 |
| school infrastructure | maintenance dashboard | 0.5496 |
| infrastructure maintenance abstract | transparent repair tracking | 0.5468 |
| abstract school infrastructure | repair requests | 0.5343 |
| facilities maintenance dashboard | public facility management | 0.4994 |
| crowdsourcing platform school | location data | 0.4654 |
| facilities maintenance | geotagged photographs | 0.4203 |

Rows compare rank positions, not one-to-one phrase replacements.

| Settings variant | Count | Concepts differing from default |
| --- | ---: | --- |
| no_mmr | 10 | location maps |
| higher_diversity | 10 | None |
| higher_semantic_floor | 7 | None |

## vision.txt

Raw KeyBERT: 0.863s. Improved extractor: 0.261s.

| Raw KeyBERT concept | Improved concept | Selection score |
| --- | --- | ---: |
| agricultural dataset image | plant disease image classification | 0.8157 |
| deep learning plant | automated plant disease detection | 0.7193 |
| classify leaf images | agricultural dataset | 0.5812 |
| plant disease detection | convolutional neural networks | 0.5741 |
| crop inspection | computer vision | 0.5714 |
| plant disease image | deep learning | 0.5517 |
| vision automated plant | tomato leaf images | 0.5505 |
| diagnosis reduce crop | manual crop inspection | 0.5178 |
| tomato leaf images | image processing | 0.4927 |
| disease detection convolutional | transfer learning | 0.4871 |

Rows compare rank positions, not one-to-one phrase replacements.

| Settings variant | Count | Concepts differing from default |
| --- | ---: | --- |
| no_mmr | 10 | None |
| higher_diversity | 10 | data augmentation |
| higher_semantic_floor | 9 | None |

## security.txt

Raw KeyBERT: 0.981s. Improved extractor: 0.334s.

| Raw KeyBERT concept | Improved concept | Selection score |
| --- | --- | ---: |
| intrusion detection cloud | network intrusion detection | 0.7601 |
| intrusion detection using | cloud applications | 0.6578 |
| detection cloud applications | anomaly detection | 0.6360 |
| detection network intrusion | threat alerts | 0.5923 |
| intrusion detection | security dashboard | 0.5809 |
| network intrusion | cybersecurity | 0.5298 |
| investigate anomaly detection | unusual authentication patterns | 0.5188 |
| intrusion detection evaluate | incident management | 0.5065 |
| anomaly detection network | static security rules | 0.5037 |
| network intrusion detection | credential theft | 0.4944 |

Rows compare rank positions, not one-to-one phrase replacements.

| Settings variant | Count | Concepts differing from default |
| --- | ---: | --- |
| no_mmr | 10 | network traffic analysis; emerging attacks; frequent false alarms |
| higher_diversity | 10 | frequent false alarms |
| higher_semantic_floor | 10 | network traffic analysis; emerging attacks |

## Interpretation

The improved outputs remove heading/verb fragments and repeated nested variants visible in the raw baseline. All three domains retain meaningful topic coverage. The settings variants show selection tradeoffs, not a measured accuracy ranking; retain modest diversity (0.15) pending human-labelled real-proposal evaluation.

The automatic checks cover local configuration failure, empty/generic content, acronym and single-word terms, long text, excluded references, duplicates, grammatical regressions, and broad domain coverage. The JSON CLI also passed from a different working directory.

Limitations: English POS errors, unknown headings, unlabelled names, lexical/semantic deduplication mistakes, candidate limits, and synthetic-only evaluation remain. Physical internet disconnection was not performed by the agent; the Python socket guard and empty-cache tests passed.

See [methodology and commands](CONCEPT_EXTRACTION.md) and [complete diagnostics and setting outputs](quality_results.json).
